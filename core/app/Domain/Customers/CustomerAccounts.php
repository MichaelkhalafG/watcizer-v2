<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Access\UserWrites;
use App\Domain\Media\MediaStore;
use App\Models\User;
use App\Support\Coerce;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The customer's own account — created, edited and secured by core (Phase 1, piece 3, 2026-09-21).
 *
 * ── What this is, next to `DashboardAccounts` ───────────────────────────────────────────────
 *
 * `App\Domain\Access\DashboardAccounts` is about who may open `/manage`. This is about who may
 * shop. They write the same legacy table and they are deliberately NOT one class: a screen — or a
 * service — that can do both is one where somebody eventually grants a dashboard role by editing a
 * customer. Nothing here touches `core_user_roles`, and nothing here writes `type` as anything but
 * the enum's least-privileged value.
 *
 * Both go through the one lock, {@see UserWrites}, which declares every permitted operation. This
 * class opens it with `customer.*` reasons and no others.
 *
 * ── The columns, and the three that stay untouched ──────────────────────────────────────────
 *
 *   register        → first_name, last_name, email, password, phone_number, image, type
 *   updateProfile   → first_name, last_name, phone_number, image
 *   changePassword  → password
 *   removeAvatar    → image
 *
 * **`last_login_at` is NOT written, and that is a deliberate deviation from the legacy app.** The
 * legacy `login()` did `$user->forceFill(['last_login_at' => now()])->save()` on every sign-in, for
 * a re-engagement mail campaign. AGENTS §3 names that column specifically: *"the column exists and
 * the legacy app maintains it; core reading the table does not entitle it to write the column.
 * Anything that wants 'last seen' builds a core-owned table."* So after Phase 2 the column stops
 * advancing and freezes at whatever the legacy host last wrote. If the campaign is ever revived it
 * needs a core-owned table, which is a feature nobody has asked for.
 *
 * `remember_token` and `last_reengagement_at` are untouched for the reasons wave 4A established,
 * and {@see User}'s three overrides make the first unreachable regardless.
 *
 * ── Validation lives HERE, not only in the request ──────────────────────────────────────────
 *
 * The controller validates SHAPE, because the storefront needs the legacy error format. The
 * invariants — the e-mail is free, the password is long enough, the current one is right — are
 * enforced in this class, so a console command or an importer cannot route around them by not
 * being an HTTP request. Same reasoning as `ProductWriter`, where the refusal is in the writer.
 */
final class CustomerAccounts
{
    public const REGISTER = 'customer.register';

    public const PROFILE = 'customer.profile';

    public const PASSWORD = 'customer.password';

    public const AVATAR = 'customer.avatar';

    public const RESET = 'customer.reset';

    public const VERIFIED = 'customer.verified';

    /** The media type customer avatars are stored under — folder `Uploads_Images/User`. */
    public const AVATAR_TYPE = 'user';

    /** Matches the legacy `Rules\Password::defaults()`, which is `min(8)` on that installation. */
    public const MIN_PASSWORD = 8;

    /** The legacy `type` a customer account receives. Never `Admin`, never `SuperAdmin`. */
    public const CREATED_TYPE = 'User';

    public function __construct(
        private readonly MediaStore $media,
        private readonly CustomerTokens $tokens,
    ) {}

    /**
     * Create a shopping account.
     *
     * @param  array{first_name: string, last_name: string, email: string, password: string, phone_number?: string|null}  $data
     *
     * @throws ValidationException when the e-mail is taken or the password is too short
     */
    public function register(array $data, ?UploadedFile $avatar = null): User
    {
        $email = self::normaliseEmail($data['email']);

        /*
         * Checked here for the SENTENCE, exactly as `DashboardAccounts::create()` does.
         * `users_email_unique` is the mechanism and it stays — but a 1062 reaches a shopper as a
         * 500 page, and "that e-mail already has an account" is something they can act on.
         */
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }

        self::assertPassword($data['password']);

        $image = $avatar === null ? null : $this->storeAvatar($avatar);

        return UserWrites::open(self::REGISTER, function () use ($data, $email, $image): User {
            $user = new User;
            $user->forceFill([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'email' => $email,
                // The `hashed` cast bcrypts it; config/hashing.php pins the cost to 10 so the row
                // is indistinguishable in form from one the legacy storefront wrote.
                'password' => $data['password'],
                'phone_number' => self::trimmedOrNull($data['phone_number'] ?? null),
                'image' => $image,
                'type' => self::CREATED_TYPE,
            ]);
            $user->save();

            return $user;
        });
    }

    /**
     * Edit the customer's own name, telephone and photo.
     *
     * A new avatar REPLACES the stored filename and the old file is deleted, which is what the
     * legacy app did. The order matters and is the way round it is on purpose: the new file is
     * written first, the column is pointed at it, and only then is the old one removed — so a
     * failure at any step leaves a row pointing at a file that exists.
     *
     * @param  array{first_name: string, last_name: string, phone_number?: string|null}  $data
     */
    public function updateProfile(User $user, array $data, ?UploadedFile $avatar = null): User
    {
        $previous = Coerce::nstr($user->getAttribute('image'));
        $image = $avatar === null ? null : $this->storeAvatar($avatar);

        UserWrites::open(self::PROFILE, function () use ($user, $data, $image): void {
            $user->forceFill(array_filter([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'image' => $image,
            ], fn (?string $value) => $value !== null) + [
                // Not in the filter: clearing the telephone is a thing a customer may do, so an
                // explicit null has to survive. The legacy rule is `nullable|string|max:20` here —
                // looser than registration's `01xxxxxxxxx` regex, and reproduced rather than
                // tightened, because tightening it would refuse a number somebody already saved.
                'phone_number' => self::trimmedOrNull($data['phone_number'] ?? null),
            ]);
            $user->save();
        });

        if ($image !== null && $previous !== null && $previous !== $image) {
            MediaStore::forgetFile(self::AVATAR_TYPE, $previous);
        }

        return $user->refresh();
    }

    /**
     * Set or change the customer's password.
     *
     * ── Why `$current` is OPTIONAL, and why that is not a hole ──────────────────────────────
     *
     * A social-login account starts password-less: `social_accounts` proves the identity and
     * `users.password` is null. Such a customer SETS a password here without supplying one, which
     * is the only way they could ever get one. Everybody else must confirm theirs, and the check is
     * in this method rather than in a form request because this is the door.
     *
     * @throws ValidationException when the current password is wrong or the new one is too short
     */
    public function changePassword(User $user, ?string $current, string $replacement): void
    {
        $stored = Coerce::nstr($user->getAttribute('password'));

        if ($stored !== null) {
            if ($current === null || $current === '' || ! Hash::check($current, $stored)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
        }

        self::assertPassword($replacement);

        UserWrites::open(self::PASSWORD, function () use ($user, $replacement): void {
            $user->forceFill(['password' => $replacement]);
            $user->save();
        });

        /*
         * LOG OUT EVERYWHERE (developer decision, 2026-09-22).
         *
         * A password change while signed in invalidates every token this customer holds — including
         * the one making this request. That is the point: if somebody else has the account, the
         * change is worthless while their session keeps working.
         *
         * The visible cost, named rather than discovered: the customer is signed out of the tab
         * they are in. The controller answers a FRESH token so a client can carry on seamlessly;
         * today's storefront does not read it, so the next request 401s and `api.jsx` signs them
         * out — which is a working outcome, just not a smooth one, and it costs no frontend change.
         */
        $this->tokens->invalidateAllFor($user, self::PASSWORD);
    }

    /**
     * Remove the customer's photo: the column is cleared and the file is deleted.
     *
     * Deleting rather than leaving it for `media:prune` is deliberate. Prune infers orphanhood by
     * scanning; this infers nothing — the row points at the file and the same operation clears the
     * pointer. And "remove my photo" that leaves the image resolving at its old URL is not what was
     * asked for, on the one action a customer takes for privacy reasons.
     */
    public function removeAvatar(User $user): User
    {
        $file = Coerce::nstr($user->getAttribute('image'));
        if ($file === null) {
            return $user;
        }

        UserWrites::open(self::AVATAR, function () use ($user): void {
            $user->forceFill(['image' => null]);
            $user->save();
        });

        MediaStore::forgetFile(self::AVATAR_TYPE, $file);

        return $user->refresh();
    }

    /**
     * Set a password from an e-mailed reset token — the broker's callback, and nothing else.
     *
     * ── Why this is separate from `changePassword()` ────────────────────────────────────────
     *
     * No current password is asked for and none could be: the customer is here BECAUSE they cannot
     * supply one. What stands in its place is the token, which `Illuminate\Auth\Passwords` has
     * already verified against `core_password_resets` before this runs — so the check has happened,
     * it has just happened somewhere else, and pretending otherwise by re-using the signed-in
     * method would put a `null` current password through a branch designed for social accounts.
     *
     * It is also its OWN `UserWrites` reason. An audit that cannot tell "changed it while signed
     * in" from "changed it holding an e-mailed token" cannot answer the only question anybody asks
     * after an account is taken over.
     *
     * @throws ValidationException when the new password is too short
     */
    public function resetPassword(User $user, string $replacement): void
    {
        self::assertPassword($replacement);

        UserWrites::open(self::RESET, function () use ($user, $replacement): void {
            /*
             * `setRememberToken(Str::random(60))` is what Laravel's own reset callback does here,
             * and it is deliberately NOT done: the model's three wave-4A overrides make it a no-op
             * anyway, and `remember_token` is named by no reason in `UserWrites::REASONS`. Core has
             * no remember-me cookie to invalidate — the customer's sessions are JWTs, and the ones
             * already issued stay valid until they expire or are revoked.
             *
             * That last sentence is a real consequence, not a detail: resetting a password does NOT
             * sign out a thief who already holds a token. Closing it needs "log out everywhere",
             * which needs a per-user epoch column, and it is a feature nobody has asked for yet.
             */
            $user->forceFill(['password' => $replacement]);
            $user->save();
        });

        /*
         * The case the whole feature exists for. A reset is performed BECAUSE somebody else may
         * have the account, so every token issued before this instant stops working — the thief's
         * included. Nothing needs to know which tokens those were.
         */
        $this->tokens->invalidateAllFor($user, self::RESET);
    }

    /**
     * Record that the customer proved they own their e-mail address.
     *
     * Idempotent by check rather than by write: a second click on the same link must not move the
     * timestamp, because "verified since" is the useful half of the column and a link can sit in an
     * inbox for weeks.
     */
    public function markVerified(User $user): bool
    {
        if ($user->getAttribute('email_verified_at') !== null) {
            return false;
        }

        UserWrites::open(self::VERIFIED, function () use ($user): void {
            $user->forceFill(['email_verified_at' => now()]);
            $user->save();
        });

        /*
         * ── The ONE place an address becomes verified, so the ONE place guest orders attach ──
         *
         * Everything that can prove an address goes through this method — the e-mailed link, and a
         * social login whose provider already verified it, INCLUDING a brand-new social account,
         * which is created unverified and then marked here rather than being stamped at birth. That
         * costs one extra UPDATE on a row milliseconds old and buys the property: there is no second
         * path by which an address becomes verified without these orders being considered.
         *
         * Non-fatal. A failure here must not undo a verification the customer completed correctly;
         * the orders stay unattached and the dashboard action exists for exactly that.
         */
        try {
            app(GuestOrderLink::class)->onVerifiedEmail($user->refresh());
        } catch (Throwable $e) {
            Log::warning('guest-order attach failed after verification', [
                'user_id' => Coerce::int($user->getKey()),
                'reason' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /** Store an uploaded avatar through the one media door and return the filename to record. */
    private function storeAvatar(UploadedFile $file): string
    {
        return Coerce::str($this->media->store($file, self::AVATAR_TYPE)['file']);
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function trimmedOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @throws ValidationException */
    private static function assertPassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw ValidationException::withMessages([
                'password' => 'The password field must be at least '.self::MIN_PASSWORD.' characters.',
            ]);
        }
    }
}
