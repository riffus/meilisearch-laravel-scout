<?php

namespace Meilisearch\Scout\Engines;

use Laravel\Scout\Builder;
use Meilisearch\Scout\Builders\MeilisearchBuilder;
use Laravel\Scout\Engines\Engine;
use MeiliSearch\Client as Meilisearch;
use Exception;
use Illuminate\Support\Facades\Log;

class MeilisearchEngine extends Engine
{
    /**
     * The Meilisearch client.
     *
     * @var Meilisearch
     */
    protected $meilisearch;

    /**
     * Determines if soft deletes for Scout are enabled or not.
     *
     * @var bool
     */
    protected $softDelete;

    public function __construct(Meilisearch $meilisearch, bool $softDelete = false)
    {
        $this->meilisearch = $meilisearch;
        $this->softDelete = $softDelete;
    }

    /**
     * Update the given model in the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection $models
     *
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->meilisearch->getIndex($models->first()->searchableAs());

        if ($this->usesSoftDelete($models->first()) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $objects = $models->map(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            return array_merge($searchableData, $model->scoutMetadata());
        })->filter()->values()->all();

        if (!empty($objects)) {
            try {
                $index->addDocuments($objects, $models->first()->getKeyName());
            } catch (Exception $e) {
                Log::error('Meilisearch error, check server status.');
            }
        }
    }

    /**
     * Remove the given model from the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection $models
     *
     * @return void
     */
    public function delete($models)
    {
        $index = $this->meilisearch->getIndex($models->first()->searchableAs());

        try {
            $index->deleteDocuments(
                $models->map->getScoutKey()
                    ->values()
                    ->all()
            );
        } catch (Exception $e) {
            Log::error('Meilisearch error, check server status.');
        }

    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'filters' => $this->filters($builder),
            'limit' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @param int $perPage
     * @param int $page
     *
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, array_filter([
            'filters' => $this->filters($builder),
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @param array $options
     *
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $meilisearch = $this->meilisearch->getIndex($builder->index ?: $builder->model->searchableAs());

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $meilisearch,
                $builder->query,
                $options
            );
        }

        return $meilisearch->search($builder->query, $options);
    }

    /**
     * Get the filter array for the query.
     *
     * @param \Laravel\Scout\Builder $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return implode(' AND ', $builder->wheres );
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param mixed $results
     *
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        if (count($results['hits']) === 0) {
            return collect();
        }

        $hits = collect($results['hits']);
        $key = key($hits->first());

        return $hits->pluck($key)->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param Builder $builder
     * @param mixed $results
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (is_null($results) || count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])->pluck($model->getKeyName())->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds(
            $builder, $objectIds
        )->filter(function ($model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param mixed $results
     *
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['nbHits'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return void
     */
    public function flush($model)
    {
        $index = $this->meilisearch->getIndex($model->searchableAs());

        try {
            $index->deleteAllDocuments();
        } catch (Exception $e) {
            Log::error('Meilisearch error, check server status.');
        }

    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model));
    }

    /**
     * Dynamically call the MeiliSearch client instance.
     *
     * @param string $method
     * @param array $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->meilisearch->$method(...$parameters);
    }
}
