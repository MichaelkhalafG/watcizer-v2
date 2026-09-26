<?php

use App\Domain\Customers\CustomerMail;
use App\Domain\Notifications\OrderEmailData;
use Illuminate\Http\Request;

/*
 * ── Every link in every e-mail, and which host it hangs on (review 🟠-5) ──────────────────────
 *
 * The defect: the admin order e-mail built its "Open Order" button with
 * `route('manage.orders.show')`. `route()` resolves its host from the CURRENT REQUEST, and that
 * e-mail is composed while serving `add_order` — which after Phase 2 is served by
 * **api.watchizereg.com**, the host whose `.htaccess` (§4) answers 404 for `/manage`.
 *
 * So every admin order notification would have carried a button to a 404, on a deploy where the
 * one application that knew the dashboard's real address was the one sending the link.
 *
 * These tests fix the SHAPE rather than the one URL: a mail link may hang on a PINNED host or on
 * the ORDER's own storefront domain, and never on the host that happens to be serving.
 */

it('builds the Open Order link on the PINNED dashboard host, whatever host is serving', function () {
    config(['notifications.manage_url' => 'https://eleganceeg.com']);

    // The request the e-mail is actually composed inside, after Phase 2.
    app()->instance('request', Request::create('https://api.watchizereg.com/api/add_order', 'POST'));

    $built = OrderEmailData::dashboardUrlFor(4321);

    expect($built)->toBe('https://eleganceeg.com/manage/orders/4321')
        ->and($built)->not->toContain('api.watchizereg.com');
});

it('the pinned path still matches the route it names', function () {
    /*
     * The path is a literal, deliberately, because `route()` is what brought the wrong host. This
     * is the guard that stops the literal drifting away from the route — the same guard
     * `ProcessedCallbackTest` puts on the payment callback path.
     */
    $routed = parse_url(route('manage.orders.show', ['order' => 4321]), PHP_URL_PATH);

    expect(OrderEmailData::MANAGE_ORDER_PATH.'4321')->toBe($routed);
});

it('MANAGE_URL overrides the pin without touching code', function () {
    config(['notifications.manage_url' => 'https://staging.example.com']);

    expect(OrderEmailData::dashboardUrlFor(7))->toBe('https://staging.example.com/manage/orders/7');
});

it('no mail TEMPLATE builds a URL of its own', function () {
    /*
     * Every link in every e-mail arrives as a prepared variable. A template that called `route()`
     * or `url()` itself would reintroduce exactly the host dependency above, in a file nobody
     * greps when they change a host.
     */
    $offenders = [];
    foreach (mailTemplates() as $file) {
        $lines = explode("\n", (string) file_get_contents($file));
        foreach ($lines as $number => $line) {
            if (! str_contains($line, '{{')) {
                continue;
            }
            foreach (['route(', 'url(', 'action(', 'secure_url(', 'asset('] as $call) {
                if (str_contains($line, $call)) {
                    $offenders[] = basename($file).':'.($number + 1).' calls '.$call;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('the builder calls no route helper and reads no host from app.url', function () {
    /*
     * The positive form of the rule: the customer's "Track Your Order" goes to the ORDER's own
     * storefront domain (so a Brand Fashion order never links a customer into Watchizer), the
     * operator's button goes to the pinned dashboard, and support goes to wa.me.
     *
     * Scanned as PHP TOKENS, not as text. A text grep matched the word `route()` inside the
     * docblock that EXPLAINS why `route()` is not used any more — a guard that fails on its own
     * explanation is a guard somebody deletes.
     */
    $source = (string) file_get_contents(app_path('Domain/Notifications/OrderEmailData.php'));
    $tokens = token_get_all($source);

    $calls = [];
    $strings = [];
    foreach ($tokens as $index => $token) {
        if (! is_array($token)) {
            continue;
        }
        if ($token[0] === T_STRING && in_array(strtolower($token[1]), ['route', 'url', 'action', 'secure_url'], true)) {
            // A bare function call, not a method call and not a declaration.
            $before = $tokens[$index - 1] ?? null;
            $isMember = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
            if (! $isMember) {
                $calls[] = $token[1].'() at line '.$token[2];
            }
        }
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($token[1], 'app.url')) {
            $strings[] = 'app.url at line '.$token[2];
        }
    }

    expect($calls)->toBe([])->and($strings)->toBe([]);
});

it('every ORDER mail template links only variables the order builder declares', function () {
    /*
     * A template can only print what its builder hands it, so the builder's declared key list is
     * the complete inventory of links an order e-mail can contain. A template printing a variable
     * the list does not carry renders a BLANK href rather than a wrong one — quieter, and worth
     * pinning for that reason.
     *
     * Scoped to the ORDER templates: `email-verification` and `password-reset` are `CustomerMail`'s
     * mailables with their own builder, and are covered by the test below.
     */
    $declared = OrderEmailData::keys();

    // Collected, not asserted one at a time: Pest's `toContain()` is VARIADIC, so a second
    // argument meant as a message becomes a second NEEDLE and the assertion changes meaning
    // silently. The failure message here is the list itself.
    $undeclared = [];
    foreach (orderMailTemplates() as $file) {
        preg_match_all(
            '/href="\{\{\s*\$([A-Za-z_][A-Za-z0-9_]*)/',
            (string) file_get_contents($file),
            $matches,
        );
        foreach ($matches[1] as $variable) {
            if (! in_array($variable, $declared, true)) {
                $undeclared[] = basename($file).' links with an undeclared $'.$variable;
            }
        }
    }

    expect($undeclared)->toBe([]);
});

it('the two CUSTOMER mails hang their link on the storefront, or on an allow-listed API path', function () {
    /*
     * `password-reset` and `email-verification` each print one `$url`, built in `CustomerMail`:
     *
     *   • the reset link on the STOREFRONT's own site — the page is the storefront's, not
     *     core's. Since L5 (2026-09-26) that is the site of the shop the reset was asked for on
     *     (`StorefrontUrls::frontend()`, passed in by the controller), no longer one global
     *     `customers.storefront_url` that sent every shop's customers to Watchizer. The behaviour
     *     itself is proven by StorefrontUrlsTest; this pins where the value comes from;
     *   • the verification link through `URL::temporarySignedRoute`, which DOES resolve the
     *     request host — and that is correct here, because the route is
     *     `api/auth/verify-email/{id}/{hash}` and §4's allow-list opens `/api/auth/` on the API
     *     host. This is the one place a request-derived host is right, so it is asserted rather
     *     than left to be re-derived by whoever next reads §4.
     */
    $source = (string) file_get_contents(app_path('Domain/Customers/CustomerMail.php'));

    $controller = (string) file_get_contents(app_path('Http/Controllers/Customer/CustomerPasswordController.php'));

    expect($source)->toContain('public function sendPasswordReset(User $user, string $token, string $frontend)')
        ->and($source)->not->toContain("config()->string('customers.storefront_url')")
        ->and($controller)->toContain('StorefrontUrls::frontend(StorefrontUrls::storefrontOf($request))')
        ->and($source)->toContain('temporarySignedRoute');

    // And the signed route really is under the prefix the allow-list opens.
    $path = parse_url(route(CustomerMail::VERIFY_ROUTE, ['id' => 1, 'hash' => str_repeat('a', 40)]), PHP_URL_PATH);

    expect($path)->toStartWith('/api/auth/');
});

/**
 * The ORDER e-mails and their shared partials — the ones OrderEmailData feeds.
 *
 * @return list<string>
 */
function orderMailTemplates(): array
{
    return array_values(array_filter(
        mailTemplates(),
        fn (string $file): bool => ! in_array(
            basename($file),
            ['email-verification.blade.php', 'password-reset.blade.php'],
            true,
        ),
    ));
}

/**
 * Every Blade file under resources/views/emails, partials included.
 *
 * @return list<string>
 */
function mailTemplates(): array
{
    $files = array_merge(
        glob(resource_path('views/emails/*.blade.php')) ?: [],
        glob(resource_path('views/emails/*/*.blade.php')) ?: [],
    );

    return $files;
}
