<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use DB, Schema, Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DBMask
{
    protected $command;
    protected $db;
    protected $maskedSchema;
    protected $materializedSchema;
    protected $tables;

    public function __construct(?Command $command=null)
    {
        $this->command = $command;
        $this->db = DB::connection(config('dbmask.connection') ?? DB::getDefaultConnectcomposeion());
        $this->sourceSchema = $this->db->getDatabaseName();
        $this->maskedSchema = config('dbmask.masked_schema');
        $this->materializedSchema = config('dbmask.materialized_schema');

        $this->tables = collect(config('dbmask')['tables'])
            ->map(function($columnTransformations) {
                return (new ColumnTransformationCollection($columnTransformations));
            });

        $this->validateConfig();
        $this->registerEnum();
        Schema::disableForeignKeyConstraints();
    }

    public function mask(): void
    {
        $this->transformTables('view', $this->maskedSchema);
    }

    public function materialize(): void
    {
        // Prepare table structure for materialized views
        $this->tables->each(function($_, string $tableName) {
            $ddl = $this->db->select("show create table $tableName")[0]->{'Create Table'};
            $this->db->unprepared("use $this->materializedSchema;");
            $this->db->statement($ddl);
            $this->db->unprepared("use $this->sourceSchema;");
        });

        $this->transformTables('table', $this->materializedSchema);
    }

    protected function transformTables(string $viewOrTable, string $schema): void
    {
        $this->registerMysqlFunctions($schema);

        $this->tables->each(function(ColumnTransformationCollection $columnTransformations, string $tableName) use ($schema, $viewOrTable){
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
            $select = "select {$this->getSelectExpression($columnTransformations)} from $tableName " . ($filter ? "where $filter; " : "; ");

            $this->db->statement(
                ($viewOrTable === 'view')
                    ? $create . ' as ' . $select
                    : "insert $schema.$tableName $select"
            );
        });
    }

    public function dropMasked()
    {
        $this->drop('view', $this->maskedSchema);
    }

    public function dropMaterialized()
    {
        $this->drop('table', $this->materializedSchema);
    }

    protected function drop(string $viewOrTable, string $schema): void
    {
        $this->log("Dropping all {$viewOrTable}s in schema <fg=blue>$schema</fg=blue>");

        $this->db->unprepared("
            start transaction;
            delete from mysql.proc where db = '$schema' and type = 'function';
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

    protected function getSelectExpression(Collection $columnTransformations): string
    {
        $select = $columnTransformations->map(function($column, $key) {
            $column = (starts_with($column, 'mask_random_')) ? $this->maskedSchema.'.'.$column : $column;
            $column = (starts_with($column, 'mask_bcrypt_')) ? "'".bcrypt(str_after($column,'mask_bcrypt_'))."'" : $column;
            return "$column as `$key`";
        })->values()->implode(', ');

        return $select ?: 'null';
    }

    protected function log(string $output): void
    {
        if ($this->command) $this->command->line($output);
    }

    protected function registerMysqlFunctions($schema): void
    {
        collect(config('dbmask')['mask_datasets'])
            ->each(function($dataset, $setname) use ($schema){
                $this->db->unprepared(
                    "drop function if exists $schema.mask_random_$setname;".
                    "create function $schema.mask_random_$setname(seed varchar(255)) returns varchar(255) return elt(".
                        "mod(conv(substring(cast(sha(seed) as char),1,16),16,10)," . count($dataset) . "-1)+1, ".
                        collect($dataset)->map(function($item){ return '"'.$item.'"'; })->implode(', ').
                    ");"
                );
            });
    }

    protected function validateConfig(): void
    {
        $sourceTables = $this->db->getDoctrineSchemaManager()->listTableNames();
        $missingTables = $this->tables->keys()->diff($sourceTables);

        if ($missingTables->isNotEmpty()) {
            throw new Exception('Config contains invalid tables: ' . $missingTables->implode(', '));
        }
    }

    protected function registerEnum(): void
    {
        $this->db->getDoctrineSchemaManager()
            ->getDatabasePlatform()
            ->registerDoctrineTypeMapping('enum', 'string');
    }
}
