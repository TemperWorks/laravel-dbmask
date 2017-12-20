<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use DB, Schema, Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DBMask
{
    protected $command;
    protected $tables;
    /** @var Connection $masksrc */
    protected $masksrc;
    /** @var Connection $masktgt */
    protected $masktgt;
    /** @var Connection $materializesrc */
    protected $materializesrc;
    /** @var Connection $materializetgt */
    protected $materializetgt;

    public function __construct(?Command $command=null)
    {
        $this->command = $command;
        $this->masksrc = DB::connection(config('dbmask.masking.source') ?? DB::getDefaultConnection());
        $this->masktgt = DB::connection(config('dbmask.masking.target'));
        $this->materializesrc = DB::connection(config('dbmask.materializing.source') ?? DB::getDefaultConnection());
        $this->materializetgt = DB::connection(config('dbmask.materializing.target'));

        $this->tables = collect(config('dbmask')['tables'])
            ->map(function($columnTransformations) {
                return (new ColumnTransformationCollection($columnTransformations));
            });

        $this->validateConfig($this->masksrc);
        $this->validateConfig($this->materializesrc);
        $this->registerEnum($this->masksrc);
        $this->registerEnum($this->materializesrc);
    }

    public function mask(): void
    {
        $this->transformTables('view', $this->masksrc, $this->masktgt);
    }

    public function materialize(): void
    {
        // Prepare table structure for materialized views
        $this->tables->each(function($_, string $tableName) {
            $ddl = $this->materializesrc->select("show create table $tableName")[0]->{'Create Table'};
            $this->materializetgt->statement($ddl);
        });

        $this->transformTables('table', $this->materializesrc, $this->materializetgt);
    }

    protected function transformTables(string $viewOrTable, Connection $src, Connection $tgt): void
    {
        $src->getSchemaBuilder()->disableForeignKeyConstraints();
        $this->registerMysqlFunctions($tgt);

        $this->tables->each(function(ColumnTransformationCollection $columnTransformations, string $tableName) use ($src, $tgt, $viewOrTable){
            $schema = $tgt->getDatabaseName();
            $this->log("creating $viewOrTable <fg=green>$tableName</fg=green> in schema <fg=blue>$schema</fg=blue>");

            $sourceTable = new SourceTable($tableName);
            $columnTransformations = $columnTransformations
                ->mergeWhen(config('dbmask.auto_include_pks'), $sourceTable->getPKColumns()->diff($columnTransformations->keys()))
                ->mergeWhen(config('dbmask.auto_include_fks'), $sourceTable->getFKColumns()->diff($columnTransformations->keys()))
                ->mergeWhen(config('dbmask.auto_include_timestamps') !== null, $sourceTable->getTimestampColumns()->diff($columnTransformations->keys()))
                ->populateKeys()
                ->sortByOrdinalPosition($sourceTable)
            ;

            $filter = config("dbmask.table_filters.$tableName");
            $create = "create $viewOrTable $schema.$tableName ";
            $select = "select {$this->getSelectExpression($columnTransformations, $src)} from $tableName " . ($filter ? "where $filter; " : "; ");

            $src->statement(
                ($viewOrTable === 'view')
                    ? $create . ' as ' . $select
                    : "insert $schema.$tableName $select"
            );
        });
        $src->getSchemaBuilder()->enableForeignKeyConstraints();
    }

    public function dropMasked()
    {
        $this->drop('view', $this->masktgt);
    }

    public function dropMaterialized()
    {
        $this->drop('table', $this->materializetgt);
    }

    protected function drop(string $viewOrTable, Connection $tgt): void
    {
        $schema = $tgt->getDatabaseName();
        $this->log("Dropping all {$viewOrTable}s in schema <fg=blue>$schema</fg=blue>");

        $tgt->unprepared("
            start transaction;
            set @t = null;
            set @@group_concat_max_len = 100000;
            select group_concat(table_schema, '.', table_name) into @t
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

    protected function getSelectExpression(Collection $columnTransformations, Connection $src): string
    {
        $schema = $src->getDatabaseName();
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

    protected function registerMysqlFunctions(Connection $tgt): void
    {
        collect(config('dbmask')['mask_datasets'])
            ->each(function($dataset, $setname) use ($tgt){
                $schema = $tgt->getDatabaseName();
                $tgt->unprepared(
                    "drop function if exists $schema.mask_random_$setname;".
                    "create function $schema.mask_random_$setname(seed varchar(255) charset utf8) returns varchar(255) deterministic return elt(".
                        "mod(conv(substring(cast(sha(seed) as char),1,16),16,10)," . count($dataset) . "-1)+1, ".
                        collect($dataset)->map(function($item){ return '"'.$item.'"'; })->implode(', ').
                    ");"
                );
            });
    }

    protected function validateConfig(Connection $src): void
    {
        $sourceTables = $src->getDoctrineSchemaManager()->listTableNames();
        $missingTables = $this->tables->keys()->diff($sourceTables);

        if ($missingTables->isNotEmpty()) {
            throw new Exception('Config contains invalid tables: ' . $missingTables->implode(', '));
        }
    }

    protected function registerEnum(Connection $src): void
    {
        $src->getDoctrineSchemaManager()
            ->getDatabasePlatform()
            ->registerDoctrineTypeMapping('enum', 'string');
    }
}
