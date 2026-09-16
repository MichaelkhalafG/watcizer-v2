<?php

namespace App\Domain\Activity;

use App\Models\User;
use App\Support\Coerce;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Who changed what, and what it was before (wave 4D).
 *
 * ── One door, called from the WRITERS ───────────────────────────────────────────────────────
 *
 * Every call goes through {@see self::record()}, and the calls live in the domain writers rather
 * than the controllers. That is deliberate: a controller is one way in, and the CLI, the importer
 * and a future endpoint are others. Logging where the write actually happens is what keeps the log
 * honest when somebody adds a second caller.
 *
 * ── Failure policy: a log must never break the thing it observes ────────────────────────────
 *
 * If this table is missing, full, or locked, the PRICE CHANGE STILL SAVES. An audit trail that can
 * refuse a legitimate business action has inverted its own value — the shop exists to sell watches,
 * not to be observable. So every write here is wrapped and swallowed, and a failure goes to the
 * application log where it is somebody's problem later rather than the operator's problem now.
 *
 * ── Never a credential value ────────────────────────────────────────────────────────────────
 *
 * {@see self::REDACTED_FIELDS} names the fields whose VALUES never enter this table. The row still
 * records that the field changed and who changed it, which is the entire audit question; copying
 * the secret into a second, less-guarded table would be a way of leaking it rather than a way of
 * auditing it. `storefront_payment_providers.credentials` is encrypted at rest precisely so it is
 * not lying around in plaintext — writing it here in the clear would undo that.
 */
final class ActivityLog
{
    public const TABLE = 'core_activity_log';

    /** Fields whose value is replaced with a marker, in both `from` and `to`. */
    public const REDACTED_FIELDS = [
        'credentials', 'password', 'secret', 'secret_key', 'api_key', 'hmac', 'hmac_secret',
        'token', 'remember_token', 'private_key',
    ];

    /*
     * NOT interface text. This marker is `json_encode`d into `core_activity_log.changes` at insert
     * time — it is stored DATA, and the log is never rewritten. Putting it on the translation seam
     * would leave yesterday's rows saying one thing and today's another, which is the exact
     * disagreement an audit trail exists to rule out. (A class constant cannot call the seam in any
     * case; the screen renders whatever the row says.)
     */
    public const REDACTED = '«محجوب»';   // i18n-exempt: stored in `changes`, never re-rendered from a key

    /**
     * Everything logged BEFORE this instant is pre-handover activity — the dashboard being built
     * and tested, not the shop being run.
     *
     * ── Why a cutoff, and why it lives here ─────────────────────────────────────────────────
     *
     * Twenty rows reached this table on 2026-09-15 from a test whose transaction was committed by
     * a stray `ALTER TABLE` (DDL implicit-commits in MariaDB). They record real grants and
     * revocations that really happened — to test fixtures, during a build. Deleting them was
     * rejected, correctly: a log we edit to say something it did not say is worth nothing the day
     * it matters.
     *
     * So they stay, and the SCREEN explains them. This constant is the only thing that decides
     * which side of the line a row falls on, and it is a display concern exclusively — nothing
     * reads it when WRITING.
     */
    public const HANDOVER_CUTOFF = '2026-09-16 00:00:00';

    /** Was this row written before the dashboard was handed to the team? */
    public static function isPreHandover(?string $createdAt): bool
    {
        return $createdAt !== null && $createdAt !== '' && $createdAt < self::HANDOVER_CUTOFF;
    }

    /**
     * Actions, so a screen filter and a writer cannot disagree about the vocabulary.
     */
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const RESTORED = 'restored';

    public const ADJUSTED = 'adjusted';

    public const GRANTED = 'granted';

    public const REVOKED = 'revoked';

    /**
     * Record one write.
     *
     * `$before` and `$after` are the raw field maps; only the fields that ACTUALLY differ are
     * stored, so a save that touched nothing writes no row at all. That last property matters more
     * than it looks: a dashboard form posts every field every time, and a log that recorded each
     * save would fill with rows saying nothing changed — which is how an audit trail becomes
     * something nobody reads.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function record(
        string $subjectType,
        ?int $subjectId,
        string $action,
        array $before = [],
        array $after = [],
        ?string $label = null,
        ?int $storefrontId = null,
    ): void {
        try {
            $changes = self::diff($before, $after);

            // An UPDATE that changed nothing is not an event.
            if ($action === self::UPDATED && $changes === []) {
                return;
            }

            $user = Auth::user();

            DB::table(self::TABLE)->insert([
                'user_id' => $user instanceof User ? Coerce::nint($user->getAuthIdentifier()) : null,
                // Captured at the time: the log must still read correctly after the account is gone.
                'user_name' => $user instanceof User ? self::nameOf($user) : null,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject_label' => $label === null ? null : mb_substr($label, 0, 191),
                'action' => $action,
                'storefront_id' => $storefrontId,
                'changes' => $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            /*
             * Swallowed on purpose — see the class docblock. The business write has already
             * happened or is about to; refusing it because the audit table is unavailable would
             * make this feature a liability.
             */
            report($e);
        }
    }

    /**
     * The fields that actually differ, as `{field: {from, to}}`, with credentials redacted.
     *
     * Comparison is LOOSE on purpose for scalars that cross the database boundary: `'99.50'` from a
     * form and `99.50` from a column are the same price, and a log that reported that as a change
     * every time would be noise indistinguishable from signal.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $newValue) {
            $oldValue = $before[$field] ?? null;

            if (self::same($oldValue, $newValue)) {
                continue;
            }

            $changes[$field] = self::isRedacted($field)
                ? ['from' => self::REDACTED, 'to' => self::REDACTED]
                : ['from' => self::readable($oldValue), 'to' => self::readable($newValue)];
        }

        return $changes;
    }

    /** Is this field's VALUE one that must never be written here? */
    public static function isRedacted(string $field): bool
    {
        $needle = strtolower($field);

        foreach (self::REDACTED_FIELDS as $secret) {
            if ($needle === $secret || str_contains($needle, $secret)) {
                return true;
            }
        }

        return false;
    }

    /** Two values that mean the same thing. */
    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a === null || $b === null) {
            // One null and one empty string is the same absence, in a database sense.
            return ($a ?? '') === '' && ($b ?? '') === '';
        }
        if (is_scalar($a) && is_scalar($b)) {
            if (is_numeric($a) && is_numeric($b)) {
                return (float) $a === (float) $b;
            }

            return (string) $a === (string) $b;
        }

        return $a == $b;                               // arrays: value equality is the right test
    }

    /** A value the screen can render — arrays and objects become short JSON, never a payload dump. */
    private static function readable(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? mb_substr($encoded, 0, 300) : '(unprintable)';
    }

    private static function nameOf(User $user): string
    {
        $first = $user->getAttribute('first_name');
        $last = $user->getAttribute('last_name');
        $name = trim((is_string($first) ? $first : '').' '.(is_string($last) ? $last : ''));

        if ($name !== '') {
            return mb_substr($name, 0, 191);
        }

        $email = $user->getAttribute('email');

        return mb_substr(is_string($email) ? $email : 'user', 0, 191);
    }

    /**
     * A database row as the field map {@see self::record()} takes.
     *
     * `(array) $stdClass` is `array<string, mixed>` in fact and `array` to PHPStan, and every
     * caller was casting it the same way at the call site. One helper, so the narrowing lives in
     * one place rather than five.
     *
     * @return array<string, mixed>
     */
    public static function fields(mixed $row): array
    {
        if (! is_object($row) && ! is_array($row)) {
            return [];
        }

        $out = [];
        foreach ((array) $row as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
