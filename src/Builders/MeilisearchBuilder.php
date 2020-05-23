<?php

namespace Meilisearch\Scout\Builders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Laravel\Scout\Builder;

class MeilisearchBuilder extends Builder
{
    public function where($field, $value)
    {
        $args = func_get_args();

        if (count($args) === 3) {
            [$field, $operator, $value] = $args;
        } else {
            $operator = '=';
        }

        switch ($operator) {
            case '=':
            case '>':
            case '<':
            case '>=':
            case '<=':
                $this->wheres[] = $field.$operator.'"'.$value.'"';
                break;

            case '!=':
            case '<>':
                $this->wheres[] = $field.'!="'.$value.'"';
                break;
        }

        return $this;
    }

    public function whereIn($field, array $values)
    {
        $terms = $this->termsBuilder($field, $values);

        if (strlen($terms) > 0) $this->wheres[] = '('.$terms.')';

        return $this;
    }

    public function whereNotIn($field, array $values)
    {
        $terms = $this->termsBuilder($field, $values);

        if (strlen($terms) > 0) $this->wheres[] = 'NOT ('.$terms.')';

        return $this;
    }

    private function termsBuilder($field, array $values)
    {
        return collect($values)->map(function ($value) use ($field) {
            return $field . '=' . '"'.$value.'"';
        })->values()->implode(' OR ');
    }

}
