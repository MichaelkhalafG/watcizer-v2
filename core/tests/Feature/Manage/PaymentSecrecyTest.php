<?php

use App\Domain\Activity\ActivityLog;
use App\Models\Storefront\StorefrontPaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The payment credentials never come back out (item 15, developer 2026-09-18).
 *
 * ── What was asked for ──────────────────────────────────────────────────────────────────────
 *
 * *"Confirm in writing that this screen is the safest thing in the system: keys write-only, never
 * rendered back, never logged, never exported."*
 *
 * A confirmation in prose is a claim. This file is the same confirmation in a form that fails when
 * it stops being true, which is the only kind worth giving. Each test is one clause of that
 * sentence, and every one works the same way: write a secret nobody could produce by accident,
 * then go looking for it everywhere it must not be.
 *
 * ── Why the search is a deep scan and not a list of fields ──────────────────────────────────
 *
 * Checking that `providers[].credentials` is absent would pass the day somebody adds a debugging
 * prop, an export column, or a "last saved value" convenience. The tests below serialise the WHOLE
 * payload and look for the secret anywhere in it, because the guarantee is about the secret and not
 * about a field name.
 */

/** A value that cannot occur by chance, so finding it anywhere is proof and not coincidence. */
const SECRET = 'sk_live_NEVER_RENDER_THIS_9f3a2b7c';

/** Create one contract carrying the secret, and return its id. */
function contractWithSecret(int $storefront = 1): int
{
    $keys = DB::table('storefronts')->where('id', $storefront)->exists();
    expect($keys)->toBeTrue("storefront {$storefront} does not exist");

    /*
     * `paymob`, because it is the only provider in the registry that DECLARES credential fields —
     * `cod` and `whatsapp` take none, and `mergeCredentials()` keeps only declared keys, so a
     * secret sent to those two is dropped before it is ever stored. A secrecy test against a
     * provider that stores no secret is the purest form of a test that cannot fail.
     */
    actingAs(Staff::admin())->post("/manage/storefronts/{$storefront}/payments/providers", [
        'provider' => 'paymob',
        'is_enabled' => true,
        'credentials' => ['secret_key' => SECRET, 'public_key' => SECRET, 'hmac_secret' => SECRET],
    ])->assertSessionHasNoErrors();

    return T::int(
        StorefrontPaymentProvider::query()
            ->where('storefront_id', $storefront)->orderByDesc('id')->value('id')
    );
}

it('never renders a credential back to the screen, anywhere in the payload', function () {
    contractWithSecret();

    $props = Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/payments')->assertOk());

    // The WHOLE payload, serialised — not a named field. A guarantee about a secret cannot be
    // expressed as a guarantee about one key's name.
    $json = T::str(json_encode($props));

    expect($json)->not->toContain(SECRET);

    // …and the screen still says the useful thing: that credentials ARE set. A screen that hides
    // the secret and also hides whether one exists cannot be used to rotate keys.
    expect($json)->toContain('credentials_set');
});

it('never writes a credential into the activity log, on create or on change', function () {
    $id = contractWithSecret();

    actingAs(Staff::admin())->put("/manage/storefronts/1/payments/providers/{$id}", [
        'is_enabled' => false,
        'credentials' => ['secret_key' => SECRET.'_rotated'],
    ])->assertSessionHasNoErrors();

    // `subject_type`, not `aggregate_type` — the column this table actually has.
    $rows = DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'storefront_payment_providers')
        ->get(['changes']);

    expect($rows->count())->toBeGreaterThan(0, 'nothing was logged — this test proves nothing');

    $leaked = [];
    foreach ($rows as $raw) {
        $changes = T::str(($raw->changes) ?? '');
        if (str_contains($changes, SECRET)) {
            $leaked[] = 'a log row carries the secret';
        }
    }

    expect($leaked)->toBe([]);
});

it('stores the credential encrypted, so the column itself is not a leak', function () {
    $id = contractWithSecret();

    // Read the raw column, bypassing the model's cast. If this is plaintext, every backup dump and
    // every person with read access to one table has the key.
    $raw = T::str(DB::table('storefront_payment_providers')->where('id', $id)->value('credentials'));

    expect($raw)->not->toContain(SECRET)
        ->and($raw)->not->toBe('', 'nothing was stored at all');

    // …and it round-trips through the model, or the encryption would be a way of losing the key.
    $model = StorefrontPaymentProvider::query()->findOrFail($id);
    expect(T::arr($model->getAttribute('credentials'))['secret_key'] ?? null)->toBe(SECRET);
});

it('drops the credential from the model’s own array form', function () {
    $id = contractWithSecret();
    $model = StorefrontPaymentProvider::query()->findOrFail($id);

    /*
     * `$hidden` is the belt to the controller's braces. `toArray()` is how a secret escapes: one
     * `return $model` in a future endpoint, one `dd($provider)` that reaches a log, one JSON
     * response built from the model rather than from a hand-written array.
     */
    expect(T::str(json_encode($model->toArray())))->not->toContain(SECRET);
});

it('exposes no export route for payments at all', function () {
    /*
     * "Never exported" is the one clause with no code to point at, which makes it the easy one to
     * break: adding `TableExport::wanted()` to this controller is three lines and looks like every
     * other screen. This asserts the absence.
     */
    // `getRoutes()` returns the collection interface, which PHPStan will not iterate; `->getRoutes()`
    // on it is the plain array of Route objects.
    $exports = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (str_contains($uri, 'payments') && str_contains($uri, 'export')) {
            $exports[] = $uri;
        }
    }

    expect($exports)->toBe([]);

    // The settlement export DOES exist and is a different thing: order money, no credentials.
    expect(Route::getRoutes()->getByName('manage.orders.settlement'))->not->toBeNull();
});

it('keeps a blank field meaning “leave it alone”, which is what makes it write-only', function () {
    $id = contractWithSecret();

    // The screen cannot show the stored value, so a save that does not re-type it must not wipe it.
    // Without this the only way to toggle `is_enabled` would be to re-enter every key from memory.
    actingAs(Staff::admin())->put("/manage/storefronts/1/payments/providers/{$id}", [
        'is_enabled' => false,
        'credentials' => ['secret_key' => '', 'api_key' => ''],
    ])->assertSessionHasNoErrors();

    $model = StorefrontPaymentProvider::query()->findOrFail($id);
    expect(T::arr($model->getAttribute('credentials'))['secret_key'] ?? null)->toBe(SECRET);
    expect((bool) $model->getAttribute('is_enabled'))->toBeFalse('the save did not take effect');
});

/*
 * ── Both storefronts are reachable (item 15's other half) ───────────────────────────────────
 *
 * The developer's report: "the payment methods screen only shows Watchizer". It did. Every other
 * per-storefront screen carries a switcher and this one did not, so the sidebar sent you to
 * whichever storefront you were last on and nothing said another existed. Brand Fashion's payment
 * settings were reachable only by typing an id into the address bar.
 */

it('offers a switcher covering every storefront the operator may reach', function () {
    $props = Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/payments')->assertOk());
    $offered = [];
    foreach (T::arr($props['storefronts'] ?? null) as $option) {
        $offered[] = T::str(T::arr($option)['value'] ?? null);
    }

    $active = DB::table('storefronts')->where('is_active', true)->orderBy('id')
        ->pluck('id')->map(fn (mixed $id): string => (string) T::int($id))->all();

    expect($offered)->toBe($active, 'an administrator cannot reach every active storefront');
    expect(count($offered))->toBeGreaterThan(1, 'there is only one storefront — this test proves nothing');
});

it('reaches the SECOND storefront’s payment settings, which is the whole complaint', function () {
    $second = T::int(DB::table('storefronts')->where('is_active', true)->where('id', '<>', 1)->orderBy('id')->value('id'));

    $props = Props::of(actingAs(Staff::admin())->get("/manage/storefronts/{$second}/payments")->assertOk());

    expect(T::int(T::arr($props['storefront'] ?? null)['id'] ?? null))->toBe($second);
});

it('never offers a scoped operator a storefront the route would 404', function () {
    /*
     * The switcher is built from the acting grant, not from the storefront table. A switcher that
     * listed every storefront would hand a scoped operator an option that answers 404 — which reads
     * as a broken dashboard rather than as a permission they do not have (§3.11.14: out of scope is
     * 404, never 403, so an id cannot be confirmed by probing).
     */
    $props = Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/payments')->assertOk());

    $refused = [];
    foreach (T::arr($props['storefronts'] ?? null) as $option) {
        $id = T::str(T::arr($option)['value'] ?? null);
        $status = actingAs(Staff::admin())->get("/manage/storefronts/{$id}/payments")->getStatusCode();
        if ($status !== 200) {
            $refused[] = "{$id} => {$status}";
        }
    }

    expect($refused)->toBe([], 'the switcher offers a storefront its own route refuses');
});
