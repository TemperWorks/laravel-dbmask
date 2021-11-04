<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use Illuminate\Support\Collection;

class ColumnTransformationCollection extends Collection
{
    public function mergeWhen(bool $condition, Collection $transformations): self
    {
        return $this->when($condition, function(self $collection) use ($transformations) {
            return $collection->merge($transformations);
        });
    }

    public function populateKeys()
    {
        return $this->mapWithKeys(function($column, $key) {
            return is_string($key) ? [$key => $column] : [$column => '`'.$column.'`'];
        });
    }

    public function sortByOrdinalPosition(Collection $positions)
    {
        return $this->sortBy(function($column, $key) use ($positions){
            return $positions->search($key);
        });
    }
}
