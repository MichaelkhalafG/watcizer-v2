<?php

use App\Domain\Catalog\LookupPatcher;
use App\Domain\Catalog\LookupWriter;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\T;

/*
 * The PARTIAL-update path for a lookup row (wave 4D) — the other half of what §2.9.7 left owed.
 *
 * ── The defect this file is the inverse of ───────────────────────────────────────────────────
 *
 * `PUT /manage/lookups/{list}/{id}` is a full-REPLACE contract, and its failure is quieter than
 * the product form's. `LookupWriter::extraColumns()` reads `$data['extra'][$column] ?? null` for
 * EVERY declared column, so an omitted key is stored as NULL — and a declared `integer` column on
 * an update is stored as **0**. On 2026-09-12 a lookup PUT without `extra.hex` left
 * `catalog/meta` serving `color_value: null` where legacy had `#111111`. Nothing on any screen
 * showed it; the compat harness caught it and a rebuild repaired it.
 *
 * The subjects below are derived from the DATA, never named: the lists that actually declare a
 * hex, an integer and a slug are looked up from `LookupWriter::all()`, so a config change makes a
 * test skip with a reason instead of asserting about a column that no longer exists (§4 law).
 */

function lookupPatcher(): LookupPatcher
{
    return app(LookupPatcher::class);
}

/**
 * The whole master row, so "only what was named changed" can be asserted literally.
 *
 * @return array<string, mixed>
 */
function lookupSnapshot(string $list, int $id): array
{
    $def = LookupWriter::definition($list);
    $row = T::row(DB::table($def['master'])->where('id', $id)->first());

    $out = [];
    foreach ((array) $row as $column => $value) {
        $out[(string) $column] = $value;
    }

    return $out;
}

/**
 * The first list declaring an extra column of `$type`, and an existing row of it — both taken
 * from the data. Returns null when this catalogue has no such subject.
 *
 * @return array{list: string, column: string, id: int}|null
 */
function lookupSubject(string $type): ?array
{
    foreach (LookupWriter::all() as $key => $def) {
        foreach ($def['extra'] as $column => $field) {
            if ((is_string($field['type'] ?? null) ? $field['type'] : 'string') !== $type) {
                continue;
            }
            $id = DB::table($def['master'])->orderBy('id')->value('id');
            if ($id !== null) {
                return ['list' => $key, 'column' => $column, 'id' => T::int($id)];
            }
        }
    }

    return null;
}

/**
 * Any list with any row — for the clauses that do not care which.
 *
 * @return array{list: string, id: int}
 */
function anyLookupRow(): array
{
    foreach (LookupWriter::all() as $key => $def) {
        $id = DB::table($def['master'])->orderBy('id')->value('id');
        if ($id !== null) {
            return ['list' => $key, 'id' => T::int($id)];
        }
    }

    throw new RuntimeException('no lookup row in this catalogue to patch');
}

function lookupName(string $list, int $id, string $locale): ?string
{
    $def = LookupWriter::definition($list);
    $value = DB::table($def['translations'])->where($def['fk'], $id)->where('locale', $locale)->value('name');

    return $value === null ? null : T::str($value);
}

// ── clause 1: only what was named ────────────────────────────────────────────────────────────

it('changes ONLY the name it was given, and leaves the hex the full-replace path would have nulled', function () {
    $subject = lookupSubject('hex');
    if ($subject === null) {
        expect(true)->toBeTrue('no list declares a hex column in this configuration');

        return;
    }

    $before = lookupSnapshot($subject['list'], $subject['id']);
    expect($before[$subject['column']])->not->toBeNull('the subject row must start with a hex to prove anything');

    $result = lookupPatcher()->patch($subject['list'], $subject['id'], ['name.en' => 'Patched Colour Name']);

    $after = lookupSnapshot($subject['list'], $subject['id']);
    $moved = [];
    foreach ($before as $column => $value) {
        if ($column === 'updated_at') {
            continue;
        }
        if (($after[$column] ?? null) !== $value) {
            $moved[] = $column;
        }
    }

    // THE assertion: a name-only patch moved no master column at all.
    expect($moved)->toBe([], 'a partial update moved a master column it was not given: '.implode(', ', $moved))
        ->and($after[$subject['column']])->toBe($before[$subject['column']])
        ->and($result->changedFields())->toBe(['name.en'])
        ->and(lookupName($subject['list'], $subject['id'], 'en'))->toBe('Patched Colour Name');
});

it('leaves an INTEGER extra column alone, where the full-replace path would have stored 0', function () {
    $subject = lookupSubject('integer');
    if ($subject === null) {
        expect(true)->toBeTrue('no list declares an integer column in this configuration');

        return;
    }

    $before = T::int(DB::table(LookupWriter::definition($subject['list'])['master'])
        ->where('id', $subject['id'])->value($subject['column']));

    /*
     * The sharpest case in this file. `extraColumns(..., isCreate: false)` answers
     * `Coerce::nint($value) ?? 0` for an integer column, so the full-replace endpoint stores ZERO
     * for a key the payload happened not to carry — not even null, which a reader might notice.
     */
    lookupPatcher()->patch($subject['list'], $subject['id'], ['name.en' => 'Sort Untouched']);

    expect(T::int(DB::table(LookupWriter::definition($subject['list'])['master'])
        ->where('id', $subject['id'])->value($subject['column'])))->toBe($before);
});

// ── clause 2: the addressable set is declared ────────────────────────────────────────────────

it('refuses a field it does not declare, names it, and writes NOTHING', function () {
    $row = anyLookupRow();
    $before = lookupSnapshot($row['list'], $row['id']);
    $nameBefore = lookupName($row['list'], $row['id'], 'en');

    expect(fn () => lookupPatcher()->patch($row['list'], $row['id'], [
        'extra.not_a_column' => 'x',
        'name.en' => 'SHOULD NOT LAND',
    ]))->toThrow(RuntimeException::class, 'extra.not_a_column');

    expect(lookupSnapshot($row['list'], $row['id']))->toBe($before)
        ->and(lookupName($row['list'], $row['id'], 'en'))->toBe($nameBefore);
});

it('refuses a locale it does not declare', function () {
    $row = anyLookupRow();

    expect(fn () => lookupPatcher()->patch($row['list'], $row['id'], ['name.fr' => 'Bleu']))
        ->toThrow(RuntimeException::class, 'name.fr');
});

it('refuses an unknown LIST rather than building a query from the caller string', function () {
    // The table name comes from config, always: a request naming an unknown list is a refusal,
    // never `DB::table($request->input('list'))`.
    expect(fn () => lookupPatcher()->patch('not_a_list', 1, ['name.en' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'not_a_list');
});

it('publishes the addressable list per LIST, so a screen can validate before it writes', function () {
    foreach (LookupWriter::all() as $key => $def) {
        $fields = LookupPatcher::addressableFields($key);

        expect($fields)->toContain('name.ar', 'name.en');
        foreach (array_keys($def['extra']) as $column) {
            expect($fields)->toContain('extra.'.$column);
        }
        // Exactly the declared set and nothing more.
        expect(count($fields))->toBe(2 + count($def['extra']), "list [{$key}] publishes a field it does not declare");
    }
});

// ── clause 3: `_complete` is refused, and so is an empty patch ───────────────────────────────

it('refuses the full-REPLACE completeness marker outright', function () {
    $row = anyLookupRow();

    expect(fn () => lookupPatcher()->patch($row['list'], $row['id'], ['_complete' => 1, 'name.en' => 'x']))
        ->toThrow(RuntimeException::class, '_complete');
});

it('refuses an empty patch instead of reporting a successful no-op', function () {
    $row = anyLookupRow();

    expect(fn () => lookupPatcher()->patch($row['list'], $row['id'], []))
        ->toThrow(RuntimeException::class, 'no fields');
});

// ── clause 4: idempotent ─────────────────────────────────────────────────────────────────────

it('is idempotent: the same patch twice changes nothing the second time', function () {
    $row = anyLookupRow();
    $def = LookupWriter::definition($row['list']);

    $first = lookupPatcher()->patch($row['list'], $row['id'], ['name.en' => 'Idempotent Name']);
    expect($first->changedCount())->toBe(1);

    $stamp = DB::table($def['master'])->where('id', $row['id'])->value('updated_at');

    $second = lookupPatcher()->patch($row['list'], $row['id'], ['name.en' => 'Idempotent Name']);

    expect($second->isNoop())->toBeTrue()
        // …and the timestamp did not move: a no-op that bumped it would move the compat payload's
        // `updated_at` with it, which the harness has no rule for outside the commerce paths (D-17).
        ->and(DB::table($def['master'])->where('id', $row['id'])->value('updated_at'))->toBe($stamp);
});

// ── clause 5: the coercions match the full-replace path exactly ──────────────────────────────

it('upper-cases a hex, the way the transform stores it (D-07)', function () {
    $subject = lookupSubject('hex');
    if ($subject === null) {
        expect(true)->toBeTrue('no list declares a hex column in this configuration');

        return;
    }

    $result = lookupPatcher()->patch($subject['list'], $subject['id'], ['extra.'.$subject['column'] => '#abcdef']);

    // CSS does not care about case; a mixed-case column makes two identical colours look different.
    expect(T::str(DB::table(LookupWriter::definition($subject['list'])['master'])
        ->where('id', $subject['id'])->value($subject['column'])))->toBe('#ABCDEF')
        ->and($result->changes['extra.'.$subject['column']]['to'])->toBe('#ABCDEF');
});

// ── clause 6: names, and the one that cannot be emptied ──────────────────────────────────────

it('refuses to empty the ARABIC name', function () {
    $row = anyLookupRow();
    $before = lookupName($row['list'], $row['id'], 'ar');

    expect(fn () => lookupPatcher()->patch($row['list'], $row['id'], ['name.ar' => '']))
        ->toThrow(RuntimeException::class, 'الاسم العربي');

    expect(lookupName($row['list'], $row['id'], 'ar'))->toBe($before);
});

it('lets the ENGLISH name be emptied, which deletes that locale — as the form path does', function () {
    $row = anyLookupRow();
    lookupPatcher()->patch($row['list'], $row['id'], ['name.en' => 'To Be Removed']);
    expect(lookupName($row['list'], $row['id'], 'en'))->toBe('To Be Removed');

    $result = lookupPatcher()->patch($row['list'], $row['id'], ['name.en' => '']);

    expect($result->changes['name.en'])->toBe(['from' => 'To Be Removed', 'to' => null])
        ->and(lookupName($row['list'], $row['id'], 'en'))->toBeNull()
        // The Arabic row is untouched, which is what keeps the lookup displayable.
        ->and(lookupName($row['list'], $row['id'], 'ar'))->not->toBeNull();
});

// ── clause 7: a slug stays unique, checked here because there is no validator ────────────────

it('refuses a slug another row already holds, and writes nothing', function () {
    $subject = lookupSubject('slug');
    if ($subject === null) {
        expect(true)->toBeTrue('no list declares a slug column in this configuration');

        return;
    }

    $def = LookupWriter::definition($subject['list']);
    $other = DB::table($def['master'])->where('id', '!=', $subject['id'])
        ->whereNotNull($subject['column'])->orderBy('id')->first(['id', $subject['column']]);

    if ($other === null) {
        expect(T::int(DB::table($def['master'])->count()))->toBe(1, 'only one row, so no slug to collide with');

        return;
    }
    $taken = T::str(Row::str(Row::cast($other), $subject['column']));
    $before = lookupSnapshot($subject['list'], $subject['id']);

    // The form path gets this from `Rule::unique(...)->ignore($id)`. A patcher has no validator,
    // so it asks the table — and a refusal must leave the row exactly as it was.
    expect(fn () => lookupPatcher()->patch($subject['list'], $subject['id'], ['extra.'.$subject['column'] => $taken]))
        ->toThrow(RuntimeException::class, $taken);

    expect(lookupSnapshot($subject['list'], $subject['id']))->toBe($before);
});

it('refuses to empty a slug, because a slug is a URL', function () {
    $subject = lookupSubject('slug');
    if ($subject === null) {
        expect(true)->toBeTrue('no list declares a slug column in this configuration');

        return;
    }

    expect(fn () => lookupPatcher()->patch($subject['list'], $subject['id'], ['extra.'.$subject['column'] => '']))
        ->toThrow(RuntimeException::class, $subject['column']);
});

// ── clause 8: it never creates ───────────────────────────────────────────────────────────────

it('refuses a row that does not exist instead of inserting one', function () {
    $row = anyLookupRow();
    $def = LookupWriter::definition($row['list']);
    $before = T::int(DB::table($def['master'])->count());

    expect(fn () => lookupPatcher()->patch($row['list'], 0, ['name.en' => 'GHOST']))
        ->toThrow(RuntimeException::class, 'never creates');

    expect(T::int(DB::table($def['master'])->count()))->toBe($before);
});
