<?php

namespace Tests\Support;

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerTokens;
use App\Models\User;

/**
 * A CUSTOMER account, created the way production creates one (storefront Phase 1, 2026-09-21).
 *
 * ── Why this may create rows where `Staff` may not ──────────────────────────────────────────
 *
 * `Tests\Support\Staff` says the suite takes accounts that ALREADY exist and grants them a role,
 * because core could not create a `users` row at all. That was true, and it stopped being true for
 * CUSTOMERS in Phase 1: registration is the feature under test, so a suite that cannot register
 * cannot test it.
 *
 * Two things keep that safe rather than merely convenient:
 *
 *   • every row goes through {@see CustomerAccounts::register()}, the same door the storefront
 *     uses — so a fixture cannot reach a state the application cannot, which is the failure mode of
 *     a factory that `forceFill`s past the rules;
 *   • every feature test runs inside `DatabaseTransactions` on both connections, so the row is
 *     gone at the end of the test. The local database's four real accounts are never touched.
 *
 * `Staff` still may not create anything, and its accounts are still the existing ones. The dividing
 * line is not "tests may now write `users`" — it is "core may create a CUSTOMER, and the suite
 * exercises that through the same door".
 */
final class Shopper
{
    /**
     * Register a customer and return the account.
     *
     * The e-mail is randomised per call so two tests in one run cannot collide on the unique index,
     * and `@example.test` is a reserved TLD that can never reach a real inbox.
     *
     * @param  array<string, string|null>  $overrides
     */
    public static function register(array $overrides = []): User
    {
        $data = $overrides + [
            'first_name' => 'Test',
            'last_name' => 'Shopper',
            'email' => self::email(),
            'password' => 'a-shopper-password',
            'phone_number' => null,
        ];

        return app(CustomerAccounts::class)->register([
            'first_name' => (string) $data['first_name'],
            'last_name' => (string) $data['last_name'],
            'email' => (string) $data['email'],
            'password' => (string) $data['password'],
            'phone_number' => $data['phone_number'] === null ? null : (string) $data['phone_number'],
        ]);
    }

    /**
     * A registered customer and a valid token for them — the pair most tests want.
     *
     * @param  array<string, string|null>  $overrides
     * @return array{0: User, 1: string}
     */
    public static function withToken(array $overrides = []): array
    {
        $user = self::register($overrides);

        return [$user, app(CustomerTokens::class)->issue($user)];
    }

    /** A fresh address in a reserved TLD, unique per call. */
    public static function email(): string
    {
        return 'shopper.'.bin2hex(random_bytes(6)).'@example.test';
    }
}
