<?php

namespace App\Traits;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Contracts\Database\Eloquent\Builder;

trait Searchable
{
    public function scopeSearch(Builder $builder, $term = '')
    {
        if (!$this->searchable) {
            throw new Exception("Please define the searchable property.");
        }

        foreach ($this->searchable as $searchable) {
            if (str_contains($searchable, '.')) {
                $relation = Str::beforeLast($searchable, '.');
                $column = Str::afterLast($searchable, '.');
                $builder->orWhereRelation($relation, $column, 'ILIKE', "%$term%");
                continue;
            }
            $builder->orWhere($searchable, 'ILIKE', "%$term%");
        }

        return $builder;
    }
}
