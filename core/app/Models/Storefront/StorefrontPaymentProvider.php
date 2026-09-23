<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `storefront_payment_providers` — ONE ROW PER CONTRACT (AGENTS §2.19, study §3.9.1).
 *
 * A provider is a contract with a payment company: credentials, one HMAC secret, one callback
 * route, per `(storefront, provider)`. The things a customer picks — card, valU, Tamara, wallet,
 * Fawry code — are METHODS underneath it ({@see StorefrontPaymentMethod}), and modelling valU or
 * Tamara as providers is the specific mistake §2.19 exists to prevent.
 *
 * ── `credentials` is encrypted, and that has consequences designed for, not discovered ───────
 *
 * The `encrypted` cast is AES-256-CBC under `APP_KEY`. So:
 *
 *  • the column can never be indexed, searched or queried BY VALUE — the ciphertext differs on
 *    every write even for the same plaintext;
 *  • **`APP_KEY` becomes payment-critical.** A key rotation needs a re-encrypt command written
 *    before it is ever attempted (backlog, study §3.9.1);
 *  • the blob is meaningless in a database dump, which is the point.
 *
 * **Nothing may render this attribute.** Not an Inertia prop, not a log line, not an exception
 * message, not a `dd()` left in a branch (AGENTS §3). The dashboard shows `credentialsSet()` — a
 * boolean — and `updated_at`, and the credential inputs are write-only: blank means "keep".
 * `PaymentSecrecyTest` asserts the attribute never appears in a rendered page or a log.
 */
class StorefrontPaymentProvider extends Model
{
    protected $table = 'storefront_payment_providers';

    /** @var list<string> */
    protected $fillable = ['storefront_id', 'provider', 'is_enabled', 'credentials', 'settings'];

    /**
     * `credentials` is hidden as well as encrypted: `toArray()` / `toJson()` is how a secret
     * reaches an Inertia prop by accident, and an Inertia page IS `toArray()`.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<Storefront, $this> */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class, 'storefront_id');
    }

    /** @return HasMany<StorefrontPaymentMethod, $this> */
    public function methods(): HasMany
    {
        return $this->hasMany(StorefrontPaymentMethod::class, 'storefront_payment_provider_id');
    }

    /**
     * Whether this contract holds credentials AT ALL — the only thing the dashboard is allowed to
     * say about them.
     *
     * `cod` and `whatsapp` legitimately hold none, so "not set" is not an error by itself; the
     * providers screen reads this together with the registry's field list to say whether the
     * contract is usable.
     */
    public function credentialsSet(): bool
    {
        $raw = $this->getAttribute('credentials');

        return is_array($raw) && $raw !== [];
    }

    /**
     * Which credential keys are present — the KEYS only, never a value.
     *
     * Used by the providers screen to show "secret_key ✓ hmac_secret ✓ public_key —" so an admin
     * can tell a half-done rotation from a finished one without anyone revealing anything.
     *
     * @return list<string>
     */
    public function credentialKeys(): array
    {
        $raw = $this->getAttribute('credentials');
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (array_keys($raw) as $key) {
            $value = $raw[$key];
            if (is_string($value) && trim($value) !== '') {
                $out[] = (string) $key;
            }
        }

        return $out;
    }

    /**
     * The required credential keys this contract does NOT hold — names only, never a value.
     *
     * `$required` comes from the provider implementation (`ProviderRegistry::credentialFields()`),
     * because what Paymob needs is Paymob's business and not this model's.
     *
     * @param  list<string>  $required
     * @return list<string>
     */
    public function missingCredentials(array $required): array
    {
        return array_values(array_diff($required, $this->credentialKeys()));
    }

    /**
     * Whether the contract holds EVERY credential its provider needs — usable, not merely present.
     *
     * ── Why `credentialsSet()` is not enough (2026-09-23) ───────────────────────────────────
     *
     * `credentialsSet()` answers "is the array non-empty". A contract holding `public_key` and
     * nothing else passes it — and that contract cannot verify a single callback, because the
     * signature check needs `hmac_secret`. So a switch gated on `credentialsSet()` goes live on a
     * half-entered contract and every callback answers 403: the money taken, the order pending,
     * and a check that read green.
     *
     * This is the ONE definition. The providers screen's "complete" badge, `aliasIsLive()`, the
     * initiator's cutover gate and the runbook's §3A.3 check all ask it, so none of them can call
     * a contract usable that another would refuse. A provider with no required fields (`cod`,
     * `whatsapp`) is complete by definition.
     *
     * @param  list<string>  $required
     */
    public function credentialsComplete(array $required): bool
    {
        return $this->missingCredentials($required) === [];
    }
}
