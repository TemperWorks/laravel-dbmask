<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use DB, Schema, Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

class DBMask
{
    protected $command;
    protected $tables;
    /** @var Connection $source */
    protected $source;
    /** @var Connection $target */
    protected $target;

    public function __construct(Connection $source, Connection $target, ?Command $command=null)
    {
        $this->command = $command;
        $this->source = $source;
        $this->target = $target;

        $this->source->getDoctrineSchemaManager()
            ->getDatabasePlatform()
            ->registerDoctrineTypeMapping('enum', 'string');

        $this->tables = collect(config('dbmask')['tables'])
            ->map(function(array $columnTransformations, string $tableName) {
                $sourceTable = new SourceTable($this->source, $tableName);
                $columnTransformations = new ColumnTransformationCollection($columnTransformations);

                return $columnTransformations
                    ->mergeWhen((bool) config('dbmask.auto_include_pks'),
                        $sourceTable->getPKColumns()->diff($columnTransformations->keys()))
                    ->mergeWhen((bool) config('dbmask.auto_include_fks'),
                        $sourceTable->getFKColumns()->diff($columnTransformations->keys()))
                    ->mergeWhen(config('dbmask.auto_include_timestamps') !== null,
                        $sourceTable->getTimestampColumns()->diff($columnTransformations->keys()))
                    ->populateKeys()
                    ->sortByOrdinalPosition($sourceTable);
            });

        $this->validateConfig();
    }

    public function mask(): void
    {
        $this->transformTables('view');
    }

    public function materialize(): void
    {
        // Prepare table structure for materialized views
        $this->target->getSchemaBuilder()->disableForeignKeyConstraints();
        $this->tables->each(function($_, string $tableName) {
            $ddl = $this->source->select("show create table $tableName")[0]->{'Create Table'};
            $this->target->statement($ddl);
        });
        $this->target->getSchemaBuilder()->enableForeignKeyConstraints();

        $this->transformTables('table');
    }

    protected function transformTables(string $viewOrTable): void
    {
        $this->source->getSchemaBuilder()->disableForeignKeyConstraints();
        $this->registerMysqlFunctions();

        $this->tables->each(function(ColumnTransformationCollection $columnTransformations, string $tableName) use ($viewOrTable){
            $schema = $this->target->getDatabaseName();
            $this->log("creating $viewOrTable <fg=green>$tableName</fg=green> in schema <fg=blue>$schema</fg=blue>");

            $filter = config("dbmask.table_filters.$tableName");
            $create = "create $viewOrTable $schema.$tableName ";
            $select = "select {$this->getSelectExpression($columnTransformations, $schema)} from $tableName " . ($filter ? "where $filter; " : "; ");

            $this->source->statement(
                ($viewOrTable === 'view')
                    ? $create . ' as ' . $select
                    : "insert $schema.$tableName $select"
            );
        });
        $this->source->getSchemaBuilder()->enableForeignKeyConstraints();
    }

    public function dropMasked()
    {
        $this->drop('view', $this->target);
    }

    public function dropMaterialized()
    {
        $this->drop('table', $this->target);
    }

    protected function drop(string $viewOrTable, Connection $tgt): void
    {
        $schema = $tgt->getDatabaseName();
        $this->log("Dropping all {$viewOrTable}s in schema <fg=blue>$schema</fg=blue>");

        $tgt->unprepared("
            start transaction;
            set @t = null;
            set @@group_concat_max_len = 100000;
            set foreign_key_checks = 0; 
            select group_concat('`', table_schema, '`.`', table_name, '`') into @t
                from information_schema.{$viewOrTable}s
                where table_schema = '$schema';
            set @t = ifnull(concat('drop $viewOrTable ', @t), '');
            prepare st from @t; execute st; drop prepare st;
            commit;"
        );
    }

    public static function random(string $source, string $function): string
    {
        return "mask_random_$function($source)";
    }

    public static function bcrypt(string $plaintext): string
    {
        return "mask_bcrypt_$plaintext";
    }

    public static function generate(int $number, callable $function): array
    {
        return collect(range(1,$number))->map($function)->toArray();
    }

    protected function getSelectExpression(Collection $columnTransformations, string $schema): string
    {
        $select = $columnTransformations->map(function($column, $key) use ($schema) {
            $column = (starts_with($column, 'mask_random_')) ? $schema.'.'.$column : $column;
            $column = (starts_with($column, 'mask_bcrypt_')) ? "'".bcrypt(str_after($column,'mask_bcrypt_'))."'" : $column;
            return "$column as `$key`";
        })->values()->implode(', ');

        return $select ?: 'null';
    }

    protected function log(string $output): void
    {
        if ($this->command) $this->command->line($output);
    }

    protected function registerMysqlFunctions(): void
    {
        collect(config('dbmask')['mask_datasets'])
            ->each(function($dataset, $setname) {
                $schema = $this->target->getDatabaseName();
                $this->target->unprepared(
                    "drop function if exists $schema.mask_random_$setname;".
                    "create function $schema.mask_random_$setname(seed varchar(255) charset utf8) returns varchar(255) deterministic return elt(".
                        "mod(conv(substring(cast(sha(seed) as char),1,16),16,10)," . count($dataset) . "-1)+1, ".
                        collect($dataset)->map(function($item){ return '"'.$item.'"'; })->implode(', ').
                    ");"
                );
            });
    }

    public function validateConfig(): void
    {
        $sourceTables = $this->source->getDoctrineSchemaManager()->listTableNames();
        $missingTables = $this->tables->keys()->diff($sourceTables);

        if ($missingTables->isNotEmpty()) {
            throw new Exception('Config contains invalid tables: ' . $missingTables->implode(', '));
        }
    }
}
