<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\DateTimeType;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use RuntimeException;
use stdClass;

class SourceTable
{
    public Table $table;
    public Connection $db;
    public string $name;
    public ColumnTransformationCollection $columnTransformations;
    protected object $rawddl;
    public string $ddl;

    public function __construct(Connection $connection, string $tableName, ?array $columnTransformations = [])
    {
        $this->name = $tableName;
        $this->db = $connection;
        $this->table = $this->db->getDoctrineSchemaManager()->listTableDetails($tableName);
        $this->rawddl = $this->getDDL();
        $this->ddl = $this->rawddl->{'Create View'} ?? $this->rawddl->{'Create Table'} ?? '';
        $this->columnTransformations = (new ColumnTransformationCollection($columnTransformations ?? []));
        $this->columnTransformations = $this->columnTransformations
            ->mergeWhen((bool) config('dbmask.auto_include_pks'), $this->getPKColumns()->diff($this->columnTransformations->keys()))
            ->mergeWhen((bool) config('dbmask.auto_include_timestamps') !== null, $this->getTimestampColumns()->diff($this->columnTransformations->keys()))
            ->populateKeys()
            ->sortByOrdinalPosition($this->getColumnOrdinalPositions());
    }

    public function getPKColumns(): ColumnTransformationCollection
    {
        try {
            return (new ColumnTransformationCollection($this->table->getPrimaryKeyColumns()));
        } catch (Exception $e) {
            // Table without PK
            return (new ColumnTransformationCollection());
        }
    }

    public function getColumnOrdinalPositions(): Collection
    {
        $schemaName = $this->db->getDatabaseName();
        $orderedcolumns = $this->db->select("select column_name as `name` from information_schema.columns where table_schema = '$schemaName' and table_name = '$this->name' order by ordinal_position");
        return collect($orderedcolumns)->pluck('name');
    }

    public function getTimestampColumns(): ColumnTransformationCollection
    {
        return collect($this->table->getColumns())
            ->reduce(function(Collection $carry, Column $column) {
                $timestampConfig = config('dbmask.auto_include_timestamps');
                $include = is_array($timestampConfig) ? in_array($column->getName(), $timestampConfig) : $column->getType() instanceof DateTimeType;
                return $include ? $carry->push($column->getName()) : $carry;
            }, new ColumnTransformationCollection());
    }

    public function getGeneratedColumns(): Collection
    {
        return collect($this->db
            ->select("select column_name as `name` from information_schema.columns where TABLE_NAME = '$this->name' and length(GENERATION_EXPRESSION) > 0"))
            ->pluck('name');
    }

    protected function getDDL(): object
    {
        try {
            return $this->db->select("show create table $this->name")[0];
        } catch (RuntimeException $exception) {
            return new stdClass();
        }
    }

    public function isView(): bool
    {
        return isset($this->rawddl->{'Create View'});
    }

    public function isTable(): bool
    {
        return isset($this->rawddl->{'Create Table'});
    }
}
