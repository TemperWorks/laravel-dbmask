<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use DB;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Types\DateTimeType;
use Illuminate\Support\Collection;

class SourceTable
{
    public $table;
    public $name;

    public function __construct(string $tableName)
    {
        $this->name = $tableName;
        $this->table = DB::connection(config('dbmask')['connection'] ?? DB::getDefaultConnection())
            ->getDoctrineSchemaManager()
            ->listTableDetails($tableName);
    }

    public function getFKColumns(): ColumnTransformationCollection
    {
        return (new ColumnTransformationCollection($this->table->getForeignKeys()))
            ->flatMap(function(ForeignKeyConstraint $key) {
                return $key->getLocalColumns();
            });
    }

    public function getPKColumns(): ColumnTransformationCollection
    {
        try {
            return (new ColumnTransformationCollection($this->table->getPrimaryKeyColumns()));
        } catch (DBALException $e) {
            return (new ColumnTransformationCollection());
        }
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
}
