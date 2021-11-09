<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use Config;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class DBMask
{
    const TARGET_MATERIALIZE = 'table';
    const TARGET_MASK = 'view';

    protected ?Command $command;
    protected Collection $tables;
    protected Connection $source;
    protected ?Connection $target;
    protected string $schema;

    protected array $filters = [];

    public function __construct(Connection $source, Connection $target, ?Command $command=null)
    {
        $this->command = $command;
        $this->source = $source;
        $this->target = $target;
        $this->schema = $this->target->getDatabaseName();

        $this->source->getDoctrineSchemaManager()
            ->getDatabasePlatform()
            ->registerDoctrineTypeMapping('enum', 'string');

        $this->tables = collect(config('dbmask')['tables'])
            ->map(fn(?array $columnTransformations, string $tableName) => new SourceTable($this->source, $tableName, $columnTransformations));
    }

    public function mask(): void
    {
        $validation = $this->validateConfig(DBMask::TARGET_MASK);
        if ($validation->except('Defined Tables Missing In DBMask Config')->isNotEmpty())
            throw new Exception($validation->toJson(JSON_PRETTY_PRINT));

        $sourceViews = Collect([]);

        $this->tables = $this->tables->filter(function(SourceTable $table) use ($sourceViews) {
            // If source table is a view, prepare to create an identical view on target after table transforms are done.
            if ($table->isView()) {
                $sourceViews->push($table->ddl);
            }
            // Only keep source tables, for source views, we are done.
            return $table->isTable();
        });

        $this->transformTables(DBMask::TARGET_MASK);

        $sourceViews->each(function(string $statement) {
            $this->target->statement($statement);
        });
    }

    public function materialize(): void
    {
        $validation = $this->validateConfig(DBMask::TARGET_MATERIALIZE);
        if ($validation->except('Defined Tables Missing In DBMask Config')->isNotEmpty())
            throw new Exception($validation->toJson(JSON_PRETTY_PRINT));

        // Prepare table structure for materialized views
        $this->target->getSchemaBuilder()->disableForeignKeyConstraints();
        $this->tables = $this->tables->filter(function(SourceTable $table) {
            $this->target->statement($table->ddl);
            // Only keep source tables, for source views, we are done.
            return ($table->isTable());
        });
        $this->target->getSchemaBuilder()->enableForeignKeyConstraints();

        $this->transformTables(DBMask::TARGET_MATERIALIZE);
    }

    protected function transformTables(string $targetType): void
    {
        $this->source->getSchemaBuilder()->disableForeignKeyConstraints();
        $this->registerMysqlFunctions();
        $this->tables->each(fn(SourceTable $table) => $this->source->statement($this->getTransformationStatement($targetType, $table->name, $table->columnTransformations)));
        $this->source->getSchemaBuilder()->enableForeignKeyConstraints();
    }

    protected function getTransformationStatement(string $targetType, string $tableName, ColumnTransformationCollection $columnTransformations): string
    {
        $this->log("creating $targetType <fg=green>$tableName</fg=green> in schema <fg=blue>$this->schema</fg=blue>");

        $create = "create $targetType $this->schema.$tableName ";
        $select = $this->getSelectExpression($targetType, $columnTransformations, $tableName);

        return ($targetType === DBMask::TARGET_MASK)
            ? $create . ' as ' . $select
            : "insert $this->schema.$tableName $select";
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

    public static function random(string $seed, string $function): string
    {
        return "mask_random_$function($seed)";
    }


    public static function bcrypt(string $plaintext): string
    {
        return "mask_bcrypt_$plaintext";
    }

    public static function generate(int $number, callable $function): array
    {
        return collect(range(1,$number))->map($function)->toArray();
    }

    protected function getSelectExpression(string $targetType, Collection $columnTransformations, string $tableName): string
    {
        $filter = data_get($this->filters, $tableName);
        $sourceTable = (new SourceTable($this->source, $tableName));
        $generated = ($targetType === DBMask::TARGET_MATERIALIZE) ? $sourceTable->getGeneratedColumns() : collect([]);

        $select = $columnTransformations->map(function($column, $key) use ($generated) {
            if ($column) $column = str_replace('mask_random_', $this->schema.'.mask_random_', $column);
            $column = Str::startsWith($column, 'mask_bcrypt_') ? "'".bcrypt(Str::after($column,'mask_bcrypt_'))."'" : $column;
            $column = $generated->contains($key) ? "default($column)" : $column;
            return "$column as `$key`";
        })->values()->implode(', ');

        $select = "select $select from $tableName " . ($filter ? "where $filter; " : "; ");

        return $select ?: 'null';
    }

    protected function log(string $output): void
    {
        if ($this->command) $this->command->line($output);
    }

    protected function registerMysqlFunctions(): void
    {
        if (!Config::has('dbmask.mask_datasets')) return;

        collect(Config::get('dbmask.mask_datasets', []))
            ->each(fn($data, $name) => $this->registerMysqlFunction($name, $data));
    }

    protected function registerMysqlFunction(string $setname, array $dataset): void
    {
        $dataset = collect($dataset);
        $items = $dataset->map(fn($v) => '"'.$v.'"')->implode(', ');
        $size = $dataset->count();

        $schema = $this->target->getDatabaseName();
        $this->target->unprepared(
            "drop function if exists $schema.mask_random_$setname;".
            "create function $schema.mask_random_$setname(seed varchar(255) charset utf8) returns varchar(255) deterministic return ".
            "elt(mod(conv(substring(cast(sha(seed) as char),1,16),16,10), $size-1) + 1, $items);"
        );
    }

    public function validateConfig(string $targetType, bool $validateSyntax = false): Collection
    {
        $notices = collect();

        if ($validateSyntax) {
            $syntaxErrors = $this->validateSyntax($targetType);
            if ($syntaxErrors->isNotEmpty()) $notices['DBMask Config contains SQL syntax errors'] = $syntaxErrors;
        }

        $schemaManager = $this->source->getDoctrineSchemaManager();

        $sourceTables = collect($schemaManager->listTableNames());
        $sourceViews = collect(array_keys($schemaManager->listViews()));
        $sourceTablesAndViews = $sourceTables->merge($sourceViews);
        $configTables = $this->tables->keys();

        $tablesMissingInSchema = $configTables->diff($sourceTablesAndViews);
        $tablesMissingInConfig = $sourceTablesAndViews->diff($configTables);

        if ($tablesMissingInSchema->isNotEmpty()) $notices['Defined Tables Missing In DB Schema'] = $tablesMissingInSchema;
        if ($tablesMissingInConfig->isNotEmpty()) $notices['Defined Tables Missing In DBMask Config'] = $tablesMissingInConfig;

        if ($targetType === DBMask::TARGET_MATERIALIZE) {
            $this->tables
                ->filter(function(SourceTable $table) use ($sourceTables) {
                    return $sourceTables->contains($table->name);
                })
                ->each(function(SourceTable $table) use ($schemaManager, $notices) {

                    $sourceColumns = collect(array_keys($schemaManager->listTableDetails($table->name)->getColumns()));
                    $configColumns = $table->columnTransformations->keys()->map(function($name) { return strtolower($name); });

                    $missingInSchema = $configColumns->diff($sourceColumns);
                    $missingInConfig = $sourceColumns->diff($configColumns);

                    if ($missingInSchema->isNotEmpty()) $notices['Defined Columns Missing In DB Schema'] = $missingInSchema;
                    if ($missingInConfig->isNotEmpty()) $notices['Defined Columns Missing In DBMask Config'] = $missingInConfig;
                });
        }

        return $notices;
    }

    protected function validateSyntax(string $targetType): Collection
    {
        $syntaxErrors = collect();

        $this->registerMysqlFunctions();
        $this->tables
            ->filter(fn(SourceTable $table) => $table->isTable())
            ->map(fn(SourceTable $table) => $this->getSelectExpression($targetType, $table->columnTransformations, $table->name))
            ->each(function($statement) use ($syntaxErrors) {
                try {
                    $this->source->select('explain '. $statement);
                } catch (RuntimeException $exception) {
                    $syntaxErrors->push($statement);
                }
            });

        return $syntaxErrors;
    }

    public function setFilters(array $filters) : void
    {
        $this->filters = $filters;
    }
}
