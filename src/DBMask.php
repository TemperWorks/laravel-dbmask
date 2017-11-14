<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use DB;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

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
        $this->db = DB::connection(config('dbmask')['connection'] ?? DB::getDefaultConnection());
        $this->sourceSchema = $this->db->getDatabaseName();
        $this->maskedSchema = config('dbmask')['masked_schema'];
        $this->materializedSchema = config('dbmask')['materialized_schema'];

        $this->tables = collect(config('dbmask')['tables'])
            ->map(function($columnTransformations) {
                return (new ColumnTransformationCollection($columnTransformations));
            });
    }

    public function mask(): void
    {
        $this->validateConfig();
        $this->registerEnum();
        $this->registerMysqlFunctions();

        $this->tables->each(function(ColumnTransformationCollection $columnTransformations, string $tableName){
            $sourceTable = new SourceTable($tableName);

            $this->log("creating masked view <fg=green>$sourceTable->name</fg=green> in schema <fg=blue>$this->maskedSchema</fg=blue>");

            $columnTransformations = $columnTransformations
                ->mergeWhen(config('dbmask.auto_include_pks'), $sourceTable->getPKColumns()->diff($columnTransformations->keys()))
                ->mergeWhen(config('dbmask.auto_include_fks'), $sourceTable->getFKColumns()->diff($columnTransformations->keys()))
                ->mergeWhen(config('dbmask.auto_include_timestamps') !== null, $sourceTable->getTimestampColumns()->diff($columnTransformations->keys()))
                ->populateKeys();

            $filter = config("dbmask.table_filters.$tableName");

            $this->db->statement(
                "create view $this->maskedSchema.$sourceTable->name as ".
                    "select {$this->getSelectExpression($columnTransformations)} ".
                    "from $sourceTable->name ".
                    ($filter ? "where $filter;" : ";")
            );
        });
    }

    public function materialize(): void
    {

    }

    public function fresh(): void
    {
        $this->log("Dropping all views in schema <fg=blue>$this->maskedSchema</fg=blue>");

        $this->db->unprepared("
            start transaction;
            delete from mysql.proc where db = '$this->maskedSchema' and type = 'function';
            set @vw = null;
            set @@group_concat_max_len = 100000;
            select group_concat(table_schema, '.', table_name) into @vw 
                from information_schema.views
                where table_schema = '$this->maskedSchema';
            set @vw = ifnull(concat('drop view ', @vw), '');
            prepare st from @vw; execute st; drop prepare st; 
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

    protected function registerMysqlFunctions(): void
    {
        collect(config('dbmask')['mask_datasets'])
            ->each(function($dataset, $setname) {
                $this->db->unprepared(
                    "drop function if exists $this->maskedSchema.mask_random_$setname;".
                    "create function $this->maskedSchema.mask_random_$setname(seed varchar(255)) returns varchar(255) return elt(".
                        "mod(conv(substring(cast(sha(seed) as char),1,16),16,10)," . count($dataset) . "-1)+1, " . collect($dataset)->map(function($item){ return '"'.$item.'"'; })->implode(', ').
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
