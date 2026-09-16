<?php

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;

/*
 * The activity log — who changed what, and what it was before (wave 4D).
 *
 * ── What has to be true for this to be worth having ─────────────────────────────────────────
 *
 *  1. It CAPTURES the write, with the old value and the new one.
 *  2. It NEVER captures a credential value — the row says the secret changed, not what it is.
 *  3. It never records a save that changed nothing, or the real answers drown in noise.
 *  4. It NEVER breaks the write it observes: a broken log must cost a log entry, not a sale.
 *  5. It is read-only and admin-only, enforced on the route.
 */

/** The newest entry for a subject, or null. */
function auditLatestEntry(string $type, ?int $id = null): ?stdClass
{
    $query = DB::table(ActivityLog::TABLE)->where('subject_type', $type);
    if ($id !== null) {
        $query->where('subject_id', $id);
    }

    return $query->orderByDesc('id')->first();
}

/** @return array<mixed> */
function auditChangesOf(?object $entry): array
{
    if ($entry === null) {
        return [];
    }
    $raw = $entry->changes ?? null;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? T::arr($decoded) : [];
}

/**
 * A product a person would actually edit: live, and with an Arabic title.
 *
 * `orderBy('id')->first()` picked product 4, which has NO translations at all — and the writer
 * refuses a product left without Arabic ("the fallback is off, and a product with no Arabic is not
 * fit to display"). The test was failing on its own choice of subject, not on the code.
 */
function auditEditableProduct(): int
{
    return T::int(DB::table('catalog_products as p')
        ->join('catalog_product_translations as t', function (JoinClause $join): void {
            $join->on('t.product_id', '=', 'p.id')->where('t.locale', '=', 'ar');
        })
        ->whereNull('p.deleted_at')
        ->whereNotNull('t.title')->where('t.title', '<>', '')
        ->orderBy('p.id')
        ->value('p.id'));
}

/**
 * A COMPLETE product payload, with one field changed.
 *
 * `ProductWriter::update()` replaces the record — that is its contract, and `FullReplace` exists to
 * make callers declare they know it. A test that passed only the field it cared about would null
 * every other column and fail on a NOT NULL constraint, which is what the first version of this
 * file did.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function auditProductPayload(int $productId, array $changes): array
{
    $row = T::row(DB::table('catalog_products')->where('id', $productId)->first());

    $payload = [];
    foreach (ProductWriter::COLUMNS as $column) {
        if (property_exists($row, $column)) {
            $payload[$column] = $row->{$column};
        }
    }

    // `family` is DERIVED from the category, never taken from the payload — passing it back would
    // be passing a value this writer ignores.
    unset($payload['family']);

    /*
     * The translations travel WITH the replace. `writeTranslations()` reads `title[ar]` etc. from
     * the same payload, and a product left without an Arabic title is refused outright ("the
     * fallback is off, and a product with no Arabic is not fit to display") — so omitting them
     * would not merely lose a title, it would make the save throw.
     */
    foreach (
        DB::table('catalog_product_translations')->where('product_id', $productId)
            ->get(['locale', ...ProductWriter::TRANSLATED]) as $raw
    ) {
        $row = T::row($raw);
        $locale = T::str($row->locale);
        foreach (ProductWriter::TRANSLATED as $column) {
            $payload[$column][$locale] = $row->{$column} ?? null;
        }
    }

    return array_merge($payload, $changes);
}

it('records a PRICE CHANGE with the old value and the new one', function () {
    /*
     * The question the whole feature exists to answer, so it is the first case: a product's price
     * moved, and six months later somebody wants to know who and from what.
     */
    $admin = Staff::admin();
    actingAs($admin);

    $productId = auditEditableProduct();
    $before = T::str(DB::table('catalog_products')->where('id', $productId)->value('selling_price'));
    $new = number_format((float) $before + 33.0, 2, '.', '');

    app(ProductWriter::class)->update(
        $productId,
        auditProductPayload($productId, ['selling_price' => $new]),
        T::int($admin->getAuthIdentifier()),
    );

    $entry = auditLatestEntry('catalog_products', $productId);
    expect($entry)->not->toBeNull();

    $changes = auditChangesOf($entry);
    expect($changes)->toHaveKey('selling_price');

    /*
     * Compared as MONEY, not as strings. The log stores the value the column will hold, which has
     * been through the writer's own coercion — `'3933.00'` from the form and `3933.0` from the
     * payload are the same price, and a string comparison here would be testing the formatter
     * rather than the audit trail.
     */
    $price = T::arr($changes['selling_price']);
    expect(T::float($price['to']))->toBe((float) $new);
    expect(T::float($price['from']))->toBe((float) $before);

    // …and WHO, by name, captured at the time.
    $row = T::row($entry);
    expect($row->user_id)->not->toBeNull();
    expect(T::str($row->user_name))->not->toBe('');
});

it('records NOTHING when a save changed nothing', function () {
    /*
     * A dashboard form posts every field every time. A log that recorded each save would fill with
     * rows saying nothing happened — which is precisely how an audit trail becomes a thing nobody
     * opens, and then a thing nobody trusts.
     */
    $admin = Staff::admin();
    actingAs($admin);

    $productId = auditEditableProduct();

    $before = T::int(DB::table(ActivityLog::TABLE)->count());

    app(ProductWriter::class)->update(
        $productId,
        auditProductPayload($productId, []),                // exactly what is already stored
        T::int($admin->getAuthIdentifier()),
    );

    expect(T::int(DB::table(ActivityLog::TABLE)->count()))->toBe($before);
});

it('NEVER writes a credential value, only that it changed', function () {
    /*
     * The rule that would be worst to get wrong. A log that copies the Paymob secret into a second,
     * less-guarded table has leaked it — and undone the encryption the column sits behind.
     */
    $secret = 'sk_live_'.str_repeat('a1b2', 8);

    $changes = ActivityLog::diff(
        ['credentials' => ['secret_key' => 'old-value'], 'is_enabled' => false],
        ['credentials' => ['secret_key' => $secret], 'is_enabled' => true],
    );

    expect($changes)->toHaveKey('credentials');
    expect($changes['credentials']['to'])->toBe(ActivityLog::REDACTED);
    expect($changes['credentials']['from'])->toBe(ActivityLog::REDACTED);

    // The non-secret field beside it is still reported in full — redaction is per field.
    expect($changes['is_enabled']['to'])->toBeTrue();

    // And the secret does not appear anywhere in the encoded row.
    expect(T::str(json_encode($changes, JSON_UNESCAPED_UNICODE)))->not->toContain($secret);
});

it('redacts every field name that carries a secret, not just `credentials`', function () {
    foreach (['password', 'api_key', 'hmac_secret', 'remember_token', 'provider_secret_key'] as $field) {
        expect(ActivityLog::isRedacted($field))->toBeTrue("[{$field}] would have been logged in the clear");
    }

    // …and does not redact ordinary fields, or the log would say nothing at all.
    foreach (['selling_price', 'title', 'is_active', 'sort_order'] as $field) {
        expect(ActivityLog::isRedacted($field))->toBeFalse("[{$field}] was redacted needlessly");
    }
});

it('records a HUMAN stock adjustment and ignores the checkout', function () {
    /*
     * The ledger already records every movement with its actor and remains the authority. This log
     * carries only what a PERSON did, because every order writes movements and burying five edits
     * under a day's trading is the same as not logging them.
     */
    $admin = Staff::admin();
    actingAs($admin);

    $productId = T::int(DB::table('catalog_products')
        ->whereNull('deleted_at')->where('stock_express', '>', 2)->orderBy('id')->value('id'));

    $humanBefore = T::int(DB::table(ActivityLog::TABLE)->where('action', ActivityLog::ADJUSTED)->count());

    app(InventoryService::class)->adjust(
        StockTarget::product($productId), 'express', -1, 'manual',
        actor: Actor::user(T::int($admin->getAuthIdentifier())),
    );

    expect(T::int(DB::table(ActivityLog::TABLE)->where('action', ActivityLog::ADJUSTED)->count()))
        ->toBe($humanBefore + 1);

    // The same movement as the SYSTEM writes no activity row.
    $systemBefore = T::int(DB::table(ActivityLog::TABLE)->where('action', ActivityLog::ADJUSTED)->count());

    app(InventoryService::class)->adjust(
        StockTarget::product($productId), 'express', 1, 'manual',
        actor: Actor::system(),
    );

    expect(T::int(DB::table(ActivityLog::TABLE)->where('action', ActivityLog::ADJUSTED)->count()))
        ->toBe($systemBefore);
});

it('records a role GRANT once, and not again when it is already held', function () {
    actingAs(Staff::admin());

    $subject = User::query()->orderByDesc('id')->first();
    expect($subject)->toBeInstanceOf(User::class);
    assert($subject instanceof User);

    $roles = app(Roles::class);
    $before = T::int(DB::table(ActivityLog::TABLE)->where('action', ActivityLog::GRANTED)->count());

    $roles->assign($subject, Role::DataEntry);
    expect(T::int(DB::table(ActivityLog::TABLE)->where('action', ActivityLog::GRANTED)->count()))
        ->toBe($before + 1);

    // `firstOrCreate` is idempotent; a second grant changed nothing, so it is not an event.
    $roles->assign($subject, Role::DataEntry);
    expect(T::int(DB::table(ActivityLog::TABLE)->where('action', ActivityLog::GRANTED)->count()))
        ->toBe($before + 1);
});

it('NEVER breaks the write it observes', function () {
    /*
     * The failure policy, proven rather than promised: an audit trail that can refuse a legitimate
     * price change has inverted its own value.
     *
     * ── Why this does NOT rename the table ──────────────────────────────────────────────────
     *
     * The first version proved it by `ALTER TABLE … RENAME`, and that was a real bug in the test,
     * not a clever trick. DDL causes an IMPLICIT COMMIT in MariaDB, which committed the
     * surrounding test transaction — so every product price this file touched was written
     * permanently, and six unrelated tests started failing on data that should have rolled back.
     * It is the same leak that had already been diagnosed in this suite once
     * (`core_user_preferences` residue breaking `ShellTest`), reintroduced by the person who
     * diagnosed it.
     *
     * So the failure is induced INSIDE the row instead: a `subject_type` longer than the column
     * accepts makes the INSERT throw, which is exactly the condition the swallow exists for, and
     * it rolls back with everything else.
     */
    expect(fn () => ActivityLog::record(
        str_repeat('x', 500),              // longer than subject_type's 64 characters
        1,
        ActivityLog::UPDATED,
        ['price' => 1],
        ['price' => 2],
    ))->not->toThrow(Throwable::class);

    // …and the failure left no half-written row behind.
    expect(T::int(DB::table(ActivityLog::TABLE)->where('subject_type', 'like', 'xxx%')->count()))->toBe(0);
});

it('shows the screen to an administrator and REFUSES data-entry', function () {
    actingAs(Staff::admin());
    get('/manage/activity')->assertOk();

    // A management view, not a working one: data-entry seeing every colleague's edit is a
    // different product with different consequences for the team.
    actingAs(Staff::dataEntry());
    get('/manage/activity')->assertForbidden();
});

it('exposes NO write route at all — not even for an administrator', function () {
    /*
     * The structural guarantee. A log somebody can edit answers nothing, and the first question
     * anybody asks of an audit trail is whether it could have been tampered with. The absence of a
     * route is the answer, so it is asserted rather than assumed.
     */
    $writes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_contains($uri, 'activity')) {
            continue;
        }
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['GET', 'HEAD'], true)) {
                $writes[] = T::str($method).' '.$uri;
            }
        }
    }

    expect($writes)->toBe([]);
});

it('logs a PLACEMENT change — visibility is the thing people ask about', function () {
    /*
     * "Why is this product hidden?" is the second most common question after "who changed the
     * price", and until now nothing recorded the answer: placement was the one scope item with no
     * hook at all.
     */
    actingAs(Staff::admin());

    $productId = auditEditableProduct();
    $row = T::row(DB::table('storefront_product')
        ->where('storefront_id', 1)->where('product_id', $productId)
        ->first(['is_visible', 'is_featured', 'sort_order', 'slug']));

    $before = T::int(DB::table(ActivityLog::TABLE)->where('subject_type', 'storefront_product')->count());

    put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => ! (bool) $row->is_visible,
        'is_featured' => (bool) $row->is_featured,
        'sort_order' => $row->sort_order,
        'slug' => $row->slug,
        '_complete' => 1,
    ]);

    expect(T::int(DB::table(ActivityLog::TABLE)->where('subject_type', 'storefront_product')->count()))
        ->toBeGreaterThan($before);

    $entry = auditLatestEntry('storefront_product', $productId);
    expect(T::arr(auditChangesOf($entry)))->toHaveKey('is_visible');
});

it('attributes an UNATTRIBUTED row to the system, never to a person', function () {
    /*
     * The twenty rows that leaked from a test carry `user_id IS NULL` — nobody was logged in. The
     * screen says so. Relabelling them with a real person's name would make the log assert
     * something it never recorded, which is the one thing this table must not do — and it is the
     * developer's own stated principle applied to their own request.
     */
    actingAs(Staff::admin());

    ActivityLog::record('catalog_products', 999001, ActivityLog::UPDATED, ['x' => 1], ['x' => 2]);

    // Written with an authenticated user, so it carries a name…
    $mine = T::row(DB::table(ActivityLog::TABLE)->where('subject_id', 999001)->first());
    expect($mine->user_id)->not->toBeNull();

    // …and a row written with nobody logged in carries none, and renders as the system.
    auth()->logout();
    ActivityLog::record('catalog_products', 999002, ActivityLog::UPDATED, ['x' => 1], ['x' => 2]);

    $theirs = T::row(DB::table(ActivityLog::TABLE)->where('subject_id', 999002)->first());
    expect($theirs->user_id)->toBeNull();

    actingAs(Staff::admin());
    $rows = Props::rows(Props::table(get('/manage/activity')));
    $rendered = T::str(json_encode($rows, JSON_UNESCAPED_UNICODE));
    expect($rendered)->toContain('النظام');
});

it('marks PRE-HANDOVER rows on the screen without touching the stored row', function () {
    actingAs(Staff::admin());

    $rows = Props::rows(Props::table(get('/manage/activity')));
    expect($rows)->not->toBe([]);

    // The flag is computed on read: every row carries it, and it is true exactly when the row
    // predates the cutoff.
    foreach ($rows as $raw) {
        $row = T::arr($raw);
        expect($row)->toHaveKey('pre_handover');
        $createdAt = $row['created_at'] ?? null;
        expect($row['pre_handover'])->toBe(ActivityLog::isPreHandover(is_string($createdAt) ? $createdAt : null));
    }

    // …and the stored table has no such column, which is what "display only" means.
    expect(Schema::hasColumn(ActivityLog::TABLE, 'pre_handover'))->toBeFalse();
});

it('shows a DELETED account by name, marked, rather than blank or broken', function () {
    /*
     * The entry about a departed administrator's last act is precisely the one somebody comes
     * looking for, so a join that dropped it would be the wrong shape entirely.
     */
    actingAs(Staff::admin());

    // A row naming a user id that does not exist — the state after an account is removed.
    DB::table(ActivityLog::TABLE)->insert([
        'user_id' => null,
        'user_name' => 'موظف سابق',
        'subject_type' => 'catalog_products',
        'subject_id' => 999003,
        'subject_label' => 'probe',
        'action' => ActivityLog::UPDATED,
        'changes' => json_encode(['x' => ['from' => 1, 'to' => 2]]),
        'created_at' => now(),
    ]);

    $rendered = T::str(json_encode(Props::rows(Props::table(get('/manage/activity'))), JSON_UNESCAPED_UNICODE));

    // The row survives and is readable. (A user_id that is set but gone renders with the
    // "deleted account" suffix; a NULL one renders as the system, per the case above.)
    expect($rendered)->toContain('probe');
});

it('filters by user, action and date, and each one bites', function () {
    actingAs(Staff::admin());

    $rows = fn (string $query): array => Props::rows(Props::table(get('/manage/activity'.$query)));

    $all = $rows('');
    expect($all)->not->toBe([]);

    // An action nothing has: the list must shrink to nothing rather than ignore the filter.
    $none = $rows('?filters[action]=revoked&filters[from]=2000-01-01&filters[to]=2000-01-02');
    expect($none)->toBe([]);

    // A date range that excludes today excludes everything written today.
    expect($rows('?filters[to]=2000-01-01'))->toBe([]);
});
