<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use Composer\Semver\Comparator;
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
        if (Comparator::lessThan($this->getMySQLVersion(), '8.0.0')) {
            return collect($this->db->getSchemaBuilder()->getColumnListing($this->table->getName()));
        }

        $schemaName = $this->db->getDatabaseName();
        $orderedcolumns = $this->db->select("select column_name from information_schema.columns where table_schema = '$schemaName' and table_name = '$this->name' order by ordinal_position");
        return collect($orderedcolumns)->pluck('column_name');
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
            ->select("select column_name from information_schema.columns where TABLE_NAME = '$this->name' and length(GENERATION_EXPRESSION) > 0"))
            ->pluck('column_name');
    }

    public function getMySQLVersion()
    {
        return $this->db->select( "select version()")[0]->{'version()'};
    }
}
