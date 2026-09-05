<?php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Base class for models that map the legacy tables through the `legacy` connection.
 *
 * What is ENFORCED here (model level): every write path of the model throws —
 * save/update/delete and their *Quietly variants, push, forceDelete, the internal
 * performInsert/performUpdate/performDeleteOnModel, the saving/deleting events, and
 * every builder write (mass update/delete, insert*, upsert, truncate, increment) via
 * LegacyReadOnlyBuilder.
 *
 * What is NOT enforced (policy only): the `legacy` connection points at the same
 * database with the same credentials, so a raw DB::connection('legacy')->table(...)
 * or DB::statement() bypasses any model-level guard. AGENTS.md §3 forbids that; the
 * transform reads through these models and writes through the default connection.
 */
abstract class LegacyModel extends Model
{
    protected $connection = 'legacy';

    protected static function booted(): void
    {
        static::saving(fn (Model $model) => static::refuse($model, 'saving'));
        static::deleting(fn (Model $model) => static::refuse($model, 'deleting'));
    }

    protected static function refuse(Model $model, string $operation): never
    {
        throw new LogicException(sprintf(
            'Legacy table [%s] is read-only for the core app (AGENTS.md §3); refused %s via %s.',
            $model->getTable(),
            $operation,
            $model::class,
        ));
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return LegacyReadOnlyBuilder<$this>
     */
    public function newEloquentBuilder($query): Builder
    {
        return LegacyReadOnlyBuilder::forModel($query, $this);
    }

    /** @param  array<string, mixed>  $options */
    public function save(array $options = []): never
    {
        static::refuse($this, 'save');
    }

    /** @param  array<string, mixed>  $options */
    public function saveQuietly(array $options = []): never
    {
        static::refuse($this, 'saveQuietly');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): never
    {
        static::refuse($this, 'update');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function updateQuietly(array $attributes = [], array $options = []): never
    {
        static::refuse($this, 'updateQuietly');
    }

    public function push(): never
    {
        static::refuse($this, 'push');
    }

    public function delete(): never
    {
        static::refuse($this, 'delete');
    }

    public function deleteQuietly(): never
    {
        static::refuse($this, 'deleteQuietly');
    }

    public function forceDelete(): never
    {
        static::refuse($this, 'forceDelete');
    }

    public function forceDeleteQuietly(): never
    {
        static::refuse($this, 'forceDeleteQuietly');
    }

    /** @param  Builder<static>  $query */
    protected function performInsert(Builder $query): never
    {
        static::refuse($this, 'performInsert');
    }

    /** @param  Builder<static>  $query */
    protected function performUpdate(Builder $query): never
    {
        static::refuse($this, 'performUpdate');
    }

    protected function performDeleteOnModel(): never
    {
        static::refuse($this, 'performDeleteOnModel');
    }
}
