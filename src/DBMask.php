<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DBMask
{
    const TARGET_MATERIALIZE = 'table';
    const TARGET_MASK = 'view';

    protected $command;
    protected $tables;
    /** @var Connection $source */
    protected $source;
    /** @var Connection $target */
    protected $target;

    /** @var array */
    protected $filters = [];

    public function __construct(Connection $source, ?Connection $target=null, ?Command $command=null)
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
                    ->mergeWhen(config('dbmask.auto_include_timestamps') !== null,
                        $sourceTable->getTimestampColumns()->diff($columnTransformations->keys()))
                    ->populateKeys()
                    ->sortByOrdinalPosition($sourceTable);
            });
    }

    public function mask(): void
    {
        $validation = $this->validateConfig(DBMask::TARGET_MASK);
        if ($validation->except('Defined Tables Missing In DBMask Config')->isNotEmpty())
            throw new Exception($validation->toJson(JSON_PRETTY_PRINT));

        $this->transformTables(DBMask::TARGET_MASK);
    }

    public function materialize(): void
    {
        $validation = $this->validateConfig(DBMask::TARGET_MATERIALIZE);
        if ($validation->except('Defined Tables Missing In DBMask Config')->isNotEmpty())
            throw new Exception($validation->toJson(JSON_PRETTY_PRINT));

        // Prepare table structure for materialized views
        $this->target->getSchemaBuilder()->disableForeignKeyConstraints();
        $this->tables->each(function($_, string $tableName) {
            $ddl = $this->source->select("show create table $tableName")[0]->{'Create Table'};
            $this->target->statement($ddl);
        });
        $this->target->getSchemaBuilder()->enableForeignKeyConstraints();

        $this->transformTables(DBMask::TARGET_MATERIALIZE);
    }

    protected function transformTables(string $targetType): void
    {
        $this->source->getSchemaBuilder()->disableForeignKeyConstraints();
        $this->registerMysqlFunctions();

        $this->tables->each(function(ColumnTransformationCollection $columnTransformations, string $tableName) use ($targetType){
            $schema = $this->target->getDatabaseName();
            $this->log("creating $targetType <fg=green>$tableName</fg=green> in schema <fg=blue>$schema</fg=blue>");

            $filter = data_get($this->filters, $tableName);
            $create = "create $targetType $schema.$tableName ";
            $select = "select {$this->getSelectExpression($columnTransformations, $schema)} from $tableName " . ($filter ? "where $filter; " : "; ");

            $this->source->statement(
                ($targetType === 'view')
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

    protected function drop(string $targetType, Connection $tgt): void
    {
        $schema = $tgt->getDatabaseName();
        $this->log("Dropping all {$targetType}s in schema <fg=blue>$schema</fg=blue>");

        $tgt->unprepared("
            start transaction;
            set @t = null;
            set @@group_concat_max_len = 100000;
            set foreign_key_checks = 0; 
            select group_concat('`', table_schema, '`.`', table_name, '`') into @t
                from information_schema.{$targetType}s
                where table_schema = '$schema';
            set @t = ifnull(concat('drop $targetType ', @t), '');
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
            $column = Str::startsWith($column, 'mask_random_') ? $schema.'.'.$column : $column;
            $column = Str::startsWith($column, 'mask_bcrypt_') ? "'".bcrypt(Str::after($column,'mask_bcrypt_'))."'" : $column;
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

    public function validateConfig(string $targetType): Collection
    {
        $notices = collect();
        $schemaManager = $this->source->getDoctrineSchemaManager();

        $sourceTables = collect($schemaManager->listTableNames());
        $configTables = $this->tables->keys();

        $tablesMissingInSchema = $configTables->diff($sourceTables);
        $tablesMissingInConfig = $sourceTables->diff($configTables);

        if ($tablesMissingInSchema->isNotEmpty()) $notices['Defined Tables Missing In DB Schema'] = $tablesMissingInSchema;
        if ($tablesMissingInConfig->isNotEmpty()) $notices['Defined Tables Missing In DBMask Config'] = $tablesMissingInConfig;

        if ($targetType === DBMask::TARGET_MATERIALIZE) {
            $exemptedColumns = config('dbmask.column_exemptions');
            $this->tables->each(function(ColumnTransformationCollection $columns, string $tableName) use ($schemaManager, $notices, $exemptedColumns) {

                $sourceColumns = collect(array_keys($schemaManager->listTableDetails($tableName)->getColumns()));
                $configColumns = $columns->keys()->map(function($name) { return strtolower($name); });

                $missingInSchema = $configColumns->diff($sourceColumns);
                $missingInConfig = $sourceColumns->diff($configColumns);

                if (key_exists($tableName, $exemptedColumns)) {
                    $columnExemptions = $exemptedColumns[$tableName];

                    $missingInConfig = $missingInConfig->filter(function($missingColumn) use ($columnExemptions) {
                        return (! in_array($missingColumn, $columnExemptions));
                    });
                }

                if ($missingInSchema->isNotEmpty()) $notices['Defined Columns Missing In DB Schema'] = $missingInSchema;
                if ($missingInConfig->isNotEmpty()) $notices['Defined Columns Missing In DBMask Config'] = $missingInConfig;
            });
        }

        return $notices;
    }

    public function setFilters(array $filters) : void
    {
        $this->filters = $filters;
    }
}
