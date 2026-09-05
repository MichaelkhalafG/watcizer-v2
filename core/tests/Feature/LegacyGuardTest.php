<?php

use App\Models\Legacy\LegacyProduct;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('refuses every model-level write path on a legacy model', function () {
    $product = LegacyProduct::query()->firstOrFail();
    $product->active = 0;

    expect(fn () => $product->save())->toThrow(LogicException::class)
        ->and(fn () => $product->saveQuietly())->toThrow(LogicException::class)
        ->and(fn () => $product->update(['active' => 0]))->toThrow(LogicException::class)
        ->and(fn () => $product->updateQuietly(['active' => 0]))->toThrow(LogicException::class)
        ->and(fn () => $product->push())->toThrow(LogicException::class)
        ->and(fn () => $product->delete())->toThrow(LogicException::class)
        ->and(fn () => $product->deleteQuietly())->toThrow(LogicException::class)
        ->and(fn () => $product->forceDelete())->toThrow(LogicException::class)
        // guarded('*') stops mass assignment first; forceCreate bypasses it and hits the guard
        ->and(fn () => LegacyProduct::query()->create(['wa_code' => 'x']))->toThrow(MassAssignmentException::class)
        ->and(fn () => LegacyProduct::query()->forceCreate(['wa_code' => 'x']))->toThrow(LogicException::class);
});

it('refuses every builder-level write path on a legacy model', function () {
    $query = fn () => LegacyProduct::query()->whereKey(1);

    expect(fn () => $query()->update(['active' => 0]))->toThrow(LogicException::class)
        ->and(fn () => $query()->delete())->toThrow(LogicException::class)
        ->and(fn () => $query()->forceDelete())->toThrow(LogicException::class)
        ->and(fn () => $query()->increment('stock'))->toThrow(LogicException::class)
        ->and(fn () => $query()->decrement('stock'))->toThrow(LogicException::class)
        ->and(fn () => $query()->touch())->toThrow(LogicException::class)
        ->and(fn () => LegacyProduct::query()->insert(['wa_code' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => LegacyProduct::query()->insertOrIgnore(['wa_code' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => LegacyProduct::query()->upsert([['wa_code' => 'x']], ['wa_code']))->toThrow(LogicException::class)
        ->and(fn () => LegacyProduct::query()->updateOrInsert(['wa_code' => 'x'], []))->toThrow(LogicException::class)
        ->and(fn () => LegacyProduct::query()->truncate())->toThrow(LogicException::class);
});
