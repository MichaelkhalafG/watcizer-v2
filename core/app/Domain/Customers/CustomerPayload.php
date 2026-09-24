<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\User;
use App\Support\Coerce;
use Illuminate\Support\Carbon;

/**
 * What a customer account looks like on the wire (Phase 1, piece 3, 2026-09-21).
 *
 * ── An EXPLICIT key list, and the reason is the one AGENTS §2.18 was written about ──────────
 *
 * The legacy `AuthController` returned `$user` and let Eloquent serialise it: every column of the
 * shared `users` table minus `$hidden`, plus the appended `has_password`. That is precisely the
 * behaviour that made core writing this table dangerous in the first place — *"the legacy app
 * serialises every column with `toArray()` on the PROXIED `login`/`register`/`me` routes, so a
 * `core_role` column would have appeared in the auth JSON the LIVE storefront consumes"*.
 *
 * Core does not repeat that. The keys below are written out, in the order the legacy response
 * carried them, so:
 *
 *   • a column added to `users` tomorrow does not silently join the public API;
 *   • `password` and `remember_token` cannot be forgotten into it — they are not on the list, which
 *     is a stronger statement than being on a `$hidden` list somebody can edit;
 *   • the shape stays pinnable by a test, which is what makes Phase 2 a flip.
 *
 * Same discipline as AGENTS rule 9 (explicit column lists, never `$fillable`) one layer out.
 *
 * ── Why the key set is the LEGACY one rather than a tidier one ──────────────────────────────
 *
 * The storefront is not being changed in Phase 1. `authStore.persist()` reads `token`, `id`,
 * `first_name`, `last_name`, `email`, `phone_number` and `image`; the account screen reads
 * `has_password` to decide whether it is CHANGING a password or SETTING one, and `user.image` off
 * the profile-save response. Dropping a key nobody appears to read is how a screen breaks three
 * weeks later, so every key the legacy response carried is still here — including
 * `last_login_at` and `last_reengagement_at`, which core no longer WRITES but still reports,
 * frozen at whatever the legacy host last put there.
 */
final class CustomerPayload
{
    /**
     * The account, in the legacy serialisation order.
     *
     * @return array<string, mixed>
     */
    public static function of(User $user): array
    {
        $password = Coerce::nstr($user->getAttribute('password'));

        return [
            'id' => Coerce::int($user->getKey()),
            'first_name' => Coerce::str($user->getAttribute('first_name')),
            'last_name' => Coerce::str($user->getAttribute('last_name')),
            'email' => Coerce::str($user->getAttribute('email')),
            'type' => Coerce::str($user->getAttribute('type')),
            'email_verified_at' => self::timestamp($user->getAttribute('email_verified_at')),
            'phone_number' => Coerce::nstr($user->getAttribute('phone_number')),
            'image' => Coerce::nstr($user->getAttribute('image')),
            'last_login_at' => self::timestamp($user->getAttribute('last_login_at')),
            'last_reengagement_at' => self::timestamp($user->getAttribute('last_reengagement_at')),
            'created_at' => self::timestamp($user->getAttribute('created_at')),
            'updated_at' => self::timestamp($user->getAttribute('updated_at')),
            /*
             * The appended attribute the legacy model carried, and the account screen depends on:
             * a social-login account has no password and is offered "Set your password" rather than
             * "Change password". Derived from presence, so the hash itself never leaves the server.
             */
            'has_password' => $password !== null && $password !== '',
        ];
    }

    /**
     * The account WITH a freshly minted token, which is what `login` and `register` answer.
     *
     * The legacy controller did `$user->token = $token` and returned the model, so `token` came
     * last in the JSON — after the appended attribute. Same order here, because the shape is a
     * contract with a frontend nobody is changing this phase.
     *
     * @return array<string, mixed>
     */
    public static function withToken(User $user, string $token): array
    {
        return self::of($user) + ['token' => $token];
    }

    /** Eloquent's datetime cast serialises as ISO-8601; a raw string is passed through unchanged. */
    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toJSON();
        }

        return Coerce::nstr($value);
    }
}
