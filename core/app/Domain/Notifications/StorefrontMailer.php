<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Storefront\StorefrontMailbox;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * The ONE place a message leaves, per storefront (W3/W4, developer: option A on 2026-10-12).
 *
 * ── Before ───────────────────────────────────────────────────────────────────────────────────
 *
 * Three send sites (`OrderMailer::deliver`, `BulkMailer::deliver`, `CustomerMail::send`) each
 * called `Mail::to()->send()` on the ONE mailer `MAIL_*` configures — Watchizer's mailbox. Every
 * Brand Fashion mail would have left from `noreply@watchizereg.com`, and the two shops would have
 * shared one daily allowance.
 *
 * ── After ────────────────────────────────────────────────────────────────────────────────────
 *
 * `send($storefrontId, …)` reads the storefront's {@see StorefrontMailbox} row, registers a mailer
 * named `storefront-{id}` from it (at runtime, in `config('mail.mailers')`, so the password never
 * sits in a config file), sets the envelope from the row, stamps the message with
 * `X-WZ-Storefront: {id}` — which is how {@see CountSentMail} attributes it to the right counter —
 * and sends. A storefront with no usable row throws {@see StorefrontHasNoMailbox}; nothing falls
 * through to the env mailer or to the other shop.
 *
 * When the application's default mailer is `log` or `array` (the test suite, a local run with
 * `MAIL_MAILER=log`, the compat harness), the message goes to THAT transport instead — nothing
 * leaves the machine — but the row is still required for its envelope and budget, so the suite
 * exercises the same path as production minus the socket.
 */
final class StorefrontMailer
{
    public const STOREFRONT_HEADER = 'X-WZ-Storefront';

    /** Transports that keep everything on this machine: no SMTP contract is needed to use them. */
    private const LOCAL_TRANSPORTS = ['log', 'array', 'null'];

    /** @var array<int, StorefrontMailbox> per request, so an order with four admins reads the row once */
    private static array $rows = [];

    /**
     * @throws StorefrontHasNoMailbox
     */
    public static function mailbox(int $storefrontId): StorefrontMailbox
    {
        if (isset(self::$rows[$storefrontId])) {
            return self::$rows[$storefrontId];
        }
        $row = StorefrontMailbox::query()->where('storefront_id', $storefrontId)->first();
        if ($row === null) {
            throw StorefrontHasNoMailbox::for($storefrontId, []);
        }
        $missing = $row->missing(! self::isLocalTransport());
        if ($missing !== []) {
            throw StorefrontHasNoMailbox::for($storefrontId, $missing);
        }

        return self::$rows[$storefrontId] = $row;
    }

    /** Drop the per-request row cache — after a row is saved, and between tests. */
    public static function forget(?int $storefrontId = null): void
    {
        if ($storefrontId === null) {
            self::$rows = [];
        } else {
            unset(self::$rows[$storefrontId]);
        }
        self::purge($storefrontId === null ? null : self::mailerName($storefrontId));
    }

    /**
     * Send one message from the storefront's mailbox. Throws what the transport throws, and
     * {@see StorefrontHasNoMailbox} before touching any transport; the caller's failure policy
     * applies, as it did with `Mail::to()->send()`.
     *
     * @throws StorefrontHasNoMailbox
     */
    public static function send(int $storefrontId, string $recipient, Mailable $mailable): void
    {
        $mailbox = self::mailbox($storefrontId);
        $mailable->from($mailbox->fromAddress(), $mailbox->fromName());
        $mailable->withSymfonyMessage(function (Email $message) use ($storefrontId): void {
            $headers = $message->getHeaders();
            $headers->remove(self::STOREFRONT_HEADER);
            $headers->addTextHeader(self::STOREFRONT_HEADER, (string) $storefrontId);
        });

        Mail::mailer(self::mailerFor($mailbox))->to($recipient)->send($mailable);
    }

    public static function mailerName(int $storefrontId): string
    {
        return 'storefront-'.$storefrontId;
    }

    /** The mailer to send through: the local transport when that is what the app runs on, else the row's. */
    private static function mailerFor(StorefrontMailbox $mailbox): string
    {
        if (self::isLocalTransport()) {
            return config()->string('mail.default');
        }
        $name = self::mailerName($mailbox->storefrontId());
        $encryption = $mailbox->encryption();
        $definition = [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $mailbox->host(),
            'port' => $mailbox->port(),
            'username' => $mailbox->username(),
            'password' => $mailbox->password(),
            'timeout' => config()->integer('mail.mailers.smtp.timeout', 10),   // the same ten seconds as the env mailer, and for the same reason
            'local_domain' => config('mail.mailers.smtp.local_domain'),
        ];
        if ($encryption === 'none') {
            $definition['auto_tls'] = false;
        }
        if (config("mail.mailers.{$name}") !== $definition) {
            config(["mail.mailers.{$name}" => $definition]);
            self::purge($name);                                             // a rotated password must not send on the old transport
        }

        return $name;
    }

    /** Forget a built mailer. The real manager only: under `Mail::fake()` there is nothing built. */
    private static function purge(?string $name): void
    {
        $manager = Mail::getFacadeRoot();                     // the real manager, or the fake under Mail::fake()
        if ($manager instanceof MailManager) {
            $manager->purge($name);
        }
    }

    private static function isLocalTransport(): bool
    {
        return in_array(config()->string('mail.default'), self::LOCAL_TRANSPORTS, true);
    }
}
