<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Support\ManageText;
use Throwable;

/**
 * Why an order e-mail failed, said WITHOUT repeating what the transport said (🟠-2, 2026-09-17).
 *
 * ── The finding ──────────────────────────────────────────────────────────────────────────────
 *
 * `recordFailure()` stored `$e::class.': '.$e->getMessage()` in `integration_outbox.last_error`, and
 * the order screen rendered it. A Symfony `TransportException` for a rejected SMTP login carries the
 * SMTP **username** and the **host:port** in its message:
 *
 *     Failed to authenticate on SMTP server with username "orders@watchizereg.com" using 2 possible
 *     authenticators … from smtp.hostinger.com:465
 *
 * So a relay misconfiguration put the shop's mail credentials — half of them, and the endpoint they
 * belong to — on a dashboard screen any operator with `view-orders` can open, and into a database
 * column that goes into every backup. Nobody had to be attacked for it to leak; it only had to fail.
 *
 * ── What is stored instead ───────────────────────────────────────────────────────────────────
 *
 * A CLASSIFICATION, which is the part anybody acts on, plus the exception class, plus a message with
 * the dangerous shapes struck out. The classification answers the only question the screen is really
 * asking — *is this us, the relay, or the address?* — and it answers it in a word an operator can
 * repeat down a telephone.
 *
 * The masked message is kept because a permanently failed send sometimes needs a developer, and
 * "TransportException" alone is not enough to start from. It is masked rather than dropped.
 */
final class MailFailure
{
    /** Our credentials were refused. Nobody's mail will send until somebody fixes the relay. */
    public const AUTH = 'auth';

    /** The relay could not be reached at all — DNS, firewall, TLS, the host being down. */
    public const CONNECT = 'connect';

    /** The relay answered and REFUSED this message: a bad recipient, a block list, a quota. */
    public const REFUSED = 'refused';

    /** Anything else — a bug in our own code on the way to the transport. */
    public const UNKNOWN = 'unknown';

    /**
     * No transport was ever reached: OUR OWN configuration is incomplete.
     *
     * These two kinds exist because 🟠-2 would otherwise have made two good messages worse. The rows
     * written by `enqueue(error: …)` are not transport text at all — we compose them ourselves, they
     * carry nothing secret, and they are the most actionable messages in the whole outbox. Passing
     * them through `kindOf()` unclassified would have labelled them *"failed for an unknown reason —
     * needs developer review"*, which is false of both: the reason is known, and neither needs a
     * developer. So they get classifications of their own rather than an exemption.
     */
    public const CONFIG = 'config';

    /** The order carries no address to send to. Not a fault — there was nobody to tell. */
    public const NO_ADDRESS = 'no_address';

    /** @var list<string> */
    public const KINDS = [self::AUTH, self::CONNECT, self::REFUSED, self::CONFIG, self::NO_ADDRESS, self::UNKNOWN];

    /**
     * Which of the four this is.
     *
     * Matched on the MESSAGE rather than the exception class, deliberately: Symfony raises the same
     * `TransportException` for a refused login and an unreachable host, and those are two different
     * jobs for two different people. The needles are lower-cased fragments that have been stable
     * across Symfony Mailer's transports for years; anything unrecognised is `unknown` rather than
     * a guess, because a wrong classification is worse than an honest one.
     */
    public static function classify(Throwable $e): string
    {
        $message = mb_strtolower($e->getMessage());

        foreach (['authenticate', 'authentication', 'auth failed', '535', '534'] as $needle) {
            if (str_contains($message, $needle)) {
                return self::AUTH;
            }
        }

        foreach (['connection could not be established', 'connection refused', 'could not connect',
            'connection timed out', 'ssl', 'tls', 'certificate', 'name or service not known',
            'temporary failure in name resolution', 'network is unreachable'] as $needle) {
            if (str_contains($message, $needle)) {
                return self::CONNECT;
            }
        }

        foreach (['recipient', 'mailbox', 'relay access denied', 'rejected', 'blocked',
            'quota', '550', '551', '552', '553', '554'] as $needle) {
            if (str_contains($message, $needle)) {
                return self::REFUSED;
            }
        }

        return self::UNKNOWN;
    }

    /**
     * What goes in `last_error`: the class, the classification, and a MASKED message.
     *
     * Four shapes are struck out, in this order, because each can carry a secret or an identity:
     *
     *   1. anything in quotes — Symfony puts the SMTP **username** there, and nothing else that
     *      appears in quotes in a transport message is worth the risk of keeping;
     *   2. `host:port` — the relay endpoint;
     *   3. bare hostnames that look like a mail relay;
     *   4. e-mail addresses — a recipient's is personal data and ours identifies the account.
     *
     * Masked rather than dropped: a permanently failed send sometimes needs a developer, and the
     * surviving words ("Failed to authenticate on SMTP server with username … using 2 possible
     * authenticators") are the ones that tell them where to look.
     */
    public static function masked(Throwable $e): string
    {
        $message = mb_substr($e->getMessage(), 0, 400);

        // 1. quoted values — the username lives here.
        $message = (string) preg_replace('/"[^"]*"/u', '"…"', $message);
        $message = (string) preg_replace("/'[^']*'/u", "'…'", $message);

        // 2. e-mail addresses, before the host rules can half-eat them.
        $message = (string) preg_replace('/[^\s<>()@]+@[^\s<>()@]+\.[a-z]{2,}/iu', '…@…', $message);

        // 3. host:port.
        $message = (string) preg_replace('/\b[a-z0-9][a-z0-9.-]*\.[a-z]{2,}:\d+/iu', '…:…', $message);

        // 4. a bare hostname with at least two dots (smtp.hostinger.com), which a relay name has and
        //    an ordinary sentence does not.
        $message = (string) preg_replace('/\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+){2,}\b/iu', '…', $message);

        return $e::class.' ['.self::classify($e).']: '.$message;
    }

    /**
     * A message WE composed, stamped with its kind — the door for every `last_error` that is not a
     * caught exception.
     *
     * The stamp is the same `[kind]` marker `masked()` writes, so `kindOf()` reads both without
     * knowing which produced the row. Nothing here is masked, because nothing here came from a
     * transport: these strings are written a few lines away from this method.
     */
    public static function ours(string $kind, string $why): string
    {
        return '['.$kind.']: '.$why;
    }

    /**
     * The classification in the operator's language — what the SCREEN shows.
     *
     * Each one says whose problem it is, because that is the decision the reader has to make.
     */
    public static function label(string $kind): string
    {
        return match ($kind) {
            self::AUTH => ManageText::t('orders.mail_fail_auth', 'رُفضت بيانات دخول خادم البريد — إعداد في الخادم، وكل الرسائل متوقفة حتى يُصلَح.'),
            self::CONNECT => ManageText::t('orders.mail_fail_connect', 'تعذّر الوصول إلى خادم البريد — مشكلة شبكة أو خادم، وليست في هذا الطلب.'),
            self::REFUSED => ManageText::t('orders.mail_fail_refused', 'خادم البريد رفض الرسالة — غالبًا عنوان المستلم.'),
            /*
             * NAMES the environment variable, on purpose. It is a variable NAME, never a value, and it
             * is the whole difference between a message an administrator can act on and one that sends
             * them to a developer (AGENTS §3 forbids writing a credential's VALUE, not its key).
             */
            self::CONFIG => ManageText::t('orders.mail_fail_config', 'لم يُضبَط مستلمو إشعارات الإدارة (ORDER_ADMIN_EMAILS) — إعداد ناقص في الخادم، فلم يَعلم أحد بالطلب.'),
            self::NO_ADDRESS => ManageText::t('orders.mail_fail_no_address', 'الطلب لا يحمل عنوان بريد للعميل، فلم تُرسَل رسالة — ليست عطلًا.'),
            default => ManageText::t('orders.mail_fail_unknown', 'فشل الإرسال لسبب غير معروف — يحتاج مراجعة من المطوّر.'),
        };
    }

    /** The stored classification of a `last_error` string, for the screen. */
    public static function kindOf(?string $lastError): ?string
    {
        if ($lastError === null || $lastError === '') {
            return null;
        }

        foreach (self::KINDS as $kind) {
            if (str_contains($lastError, '['.$kind.']: ')) {
                return $kind;
            }
        }

        /*
         * A row written before this classifier existed. `unknown` rather than null, because the
         * screen must say something about a failure it is showing — and "unknown" is true of it.
         */
        return self::UNKNOWN;
    }
}
