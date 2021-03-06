<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\DateTimeType;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

class SourceTable
{
    public $table;
    public $db;
    public $name;

    public function __construct(Connection $connection, string $tableName)
    {
        $this->name = $tableName;
        $this->db = $connection;
        $this->table = $this->db->getDoctrineSchemaManager()->listTableDetails($tableName);
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
}
