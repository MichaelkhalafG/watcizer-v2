<?php

namespace App\Models\Storefront;

use App\Domain\Notifications\StorefrontHasNoMailbox;
use App\Support\Coerce;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `storefront_mailboxes` — the mailbox a storefront's e-mail LEAVES FROM, one row per storefront
 * (W3/W4, developer: option A on 2026-10-12, built 2026-10-13).
 *
 * The shop's SMTP contract and its daily budget, in a row rather than in `MAIL_*` env keys —
 * because one set of env keys is ONE mailbox, and one mailbox for two shops means every Brand
 * Fashion mail leaves as Watchizer and the two shops share one daily allowance. Each row holds:
 *
 *  • the transport — host, port, encryption, username, the password ENCRYPTED under `APP_KEY`
 *    exactly as {@see StorefrontPaymentProvider} holds a payment contract's secret (hidden, never
 *    rendered; `credentialsSet()` is the only thing a screen may say about it);
 *  • the envelope — `from_address`, `from_name`;
 *  • the budget — `daily_cap` and `transactional_reserve`, PER MAILBOX: Watchizer exhausting its
 *    allowance does not stop Brand Fashion sending, and the reverse ({@see MailBudget});
 *  • `order_recipients` — the team addresses that get the admin copy of an order (C12: per
 *    storefront, never the other shop's; `ORDER_ADMIN_EMAILS` is retired).
 *
 * `is_enabled = false` parks the shop's mail with a reason instead of sending it. No fallback:
 * a storefront with no usable mailbox row is {@see StorefrontHasNoMailbox},
 * never another shop's mailbox.
 */
class StorefrontMailbox extends Model
{
    protected $table = 'storefront_mailboxes';

    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** @var list<string> */
    protected $fillable = [
        'storefront_id', 'is_enabled', 'host', 'port', 'encryption', 'username', 'credentials',
        'from_address', 'from_name', 'daily_cap', 'transactional_reserve', 'order_recipients',
    ];

    /** @var list<string> */
    protected $hidden = ['credentials'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'port' => 'integer',
            'daily_cap' => 'integer',
            'transactional_reserve' => 'integer',
            'credentials' => 'encrypted:array',
            'order_recipients' => 'array',
        ];
    }

    /** @return BelongsTo<Storefront, $this> */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class, 'storefront_id');
    }

    // ── typed readers: the row's columns as the types the code needs, never mixed ──────────────

    public function storefrontId(): int
    {
        return Coerce::int($this->getAttribute('storefront_id'));
    }

    public function isEnabled(): bool
    {
        return Coerce::bool($this->getAttribute('is_enabled'));
    }

    public function host(): string
    {
        return trim(Coerce::str($this->getAttribute('host')));
    }

    public function port(): int
    {
        return Coerce::int($this->getAttribute('port'));
    }

    public function encryption(): string
    {
        return Coerce::str($this->getAttribute('encryption'));
    }

    public function username(): string
    {
        return trim(Coerce::str($this->getAttribute('username')));
    }

    public function fromAddress(): string
    {
        return trim(Coerce::str($this->getAttribute('from_address')));
    }

    public function fromName(): string
    {
        return trim(Coerce::str($this->getAttribute('from_name')));
    }

    public function dailyCap(): int
    {
        return Coerce::int($this->getAttribute('daily_cap'));
    }

    public function transactionalReserve(): int
    {
        return Coerce::int($this->getAttribute('transactional_reserve'));
    }

    /** Whether a password is stored — a boolean, the only thing a screen may say about it. */
    public function credentialsSet(): bool
    {
        return $this->password() !== '';
    }

    /** The password, for the transport only. Never rendered, never logged. */
    public function password(): string
    {
        $raw = $this->getAttribute('credentials');
        $password = is_array($raw) ? ($raw['password'] ?? null) : null;

        return is_string($password) ? $password : '';
    }

    /** @return list<string> */
    public function orderRecipients(): array
    {
        $raw = $this->getAttribute('order_recipients');
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $address) {
            if (is_string($address) && trim($address) !== '') {
                $out[] = trim($address);
            }
        }

        return $out;
    }

    /**
     * What stops this mailbox being used for a real send — names only, never a value. Empty means
     * usable. `$needsTransport` is false when the application's mailer is `log` or `array`
     * (tests, a local run): the envelope and budget are still required, the SMTP contract is not.
     *
     * @return list<string>
     */
    public function missing(bool $needsTransport): array
    {
        $missing = [];
        if (! $this->isEnabled()) {
            $missing[] = 'is_enabled';
        }
        if ($this->fromAddress() === '') {
            $missing[] = 'from_address';
        }
        if ($this->fromName() === '') {
            $missing[] = 'from_name';
        }
        if ($needsTransport) {
            if ($this->host() === '') {
                $missing[] = 'host';
            }
            if ($this->port() <= 0) {
                $missing[] = 'port';
            }
            if ($this->username() === '') {
                $missing[] = 'username';
            }
            if (! $this->credentialsSet()) {
                $missing[] = 'password';
            }
            if (! in_array($this->encryption(), self::ENCRYPTIONS, true)) {
                $missing[] = 'encryption';
            }
        }
        if ($this->dailyCap() <= 0) {
            $missing[] = 'daily_cap';
        }
        if ($this->transactionalReserve() < 0 || $this->transactionalReserve() >= $this->dailyCap()) {
            $missing[] = 'transactional_reserve';
        }

        return $missing;
    }
}
