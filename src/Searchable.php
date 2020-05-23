<?php

namespace Meilisearch\Scout;

use Exception;
use Illuminate\Support\Arr;
use Laravel\Scout\Searchable as SourceSearchable;
use Meilisearch\Scout\Builders\MeilisearchBuilder;
use Laravel\Scout\Builder;

trait Searchable
{
    use SourceSearchable;

    /**
     * Execute the search.
     *
     * @param  string  $query
     * @param  callable|null  $callback
     * @return \Meilisearch\Scout\MeilisearchBuilder
     */
    public static function search($query, $callback = null)
    {
        $softDelete = static::usesSoftDelete() && config('scout.soft_delete', false);

        return app(MeilisearchBuilder::class, [
            'model' => new static,
            'query' => $query,
            'callback' => $callback,
            'softDelete'=> $softDelete,
        ]);
    }

}
