<?php

namespace App\Models\Legacy;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * Eloquent builder for legacy models: every write entry point on the builder throws.
 * Covers mass updates/deletes and the query-builder passthroughs (insert*, upsert,
 * truncate, updateOrInsert) that never touch model events.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class LegacyReadOnlyBuilder extends Builder
{
    /**
     * Generic-safe factory used by LegacyModel::newEloquentBuilder(): binding the
     * model here lets static analysis carry the concrete model type through.
     *
     * @template TBound of \Illuminate\Database\Eloquent\Model
     *
     * @param  TBound  $model
     * @return self<TBound>
     */
    public static function forModel(QueryBuilder $query, Model $model): self
    {
        /** @var self<TBound> $builder */
        $builder = new self($query);
        $builder->setModel($model);

        return $builder;
    }

    private function refuse(string $operation): never
    {
        throw new LogicException(sprintf(
            'Legacy table [%s] is read-only for the core app (AGENTS.md §3); refused builder %s().',
            $this->getModel()->getTable(),
            $operation,
        ));
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values): never
    {
        $this->refuse('update');
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        $this->refuse('upsert');
    }

    /** @param  array<string, mixed>  $extra */
    public function increment($column, $amount = 1, array $extra = []): never
    {
        $this->refuse('increment');
    }

    /** @param  array<string, mixed>  $extra */
    public function decrement($column, $amount = 1, array $extra = []): never
    {
        $this->refuse('decrement');
    }

    /** @param  array<int, string>|string|null  $column */
    public function touch($column = null): never
    {
        $this->refuse('touch');
    }

    public function delete(): never
    {
        $this->refuse('delete');
    }

    public function forceDelete(): never
    {
        $this->refuse('forceDelete');
    }

    /** @param  array<int|string, mixed>  $values */
    public function insert(array $values): never
    {
        $this->refuse('insert');
    }

    /** @param  array<string, mixed>  $values */
    public function insertGetId(array $values, ?string $sequence = null): never
    {
        $this->refuse('insertGetId');
    }

    /** @param  array<int|string, mixed>  $values */
    public function insertOrIgnore(array $values): never
    {
        $this->refuse('insertOrIgnore');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  Closure|QueryBuilder|Builder<Model>|string  $query
     */
    public function insertUsing(array $columns, Closure|QueryBuilder|Builder|string $query): never
    {
        $this->refuse('insertUsing');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|callable  $values
     */
    public function updateOrInsert(array $attributes, $values = []): never
    {
        $this->refuse('updateOrInsert');
    }

    public function truncate(): never
    {
        $this->refuse('truncate');
    }
}
