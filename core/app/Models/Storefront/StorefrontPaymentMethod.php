<?php

namespace App\Models\Storefront;

use App\Domain\Payment\MethodList;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `storefront_payment_methods` — ONE ROW PER THING A CUSTOMER CAN PICK (study §3.9.1).
 *
 * A method lives under its provider and carries that provider's `integration_id`, which is NOT a
 * secret: Paymob puts it in the signed callback payload, so the dashboard shows it normally. The
 * label is per locale and per storefront, because "Card" and "بطاقة" are the merchant's words
 * (§2.1 rule 2 — the astrotomic pattern, not a JSON column).
 *
 * ── Uniqueness is per PROVIDER, and the duplication is deliberate ────────────────────────────
 *
 * `card` may exist under Paymob AND under Fawry on one storefront — that is the arrangement the
 * client is heading for, and holding both is what makes moving card traffic between contracts
 * possible at all. The customer never sees the same method twice: the list collapses to ONE entry
 * per method key and the winner takes the money ({@see MethodList}).
 *
 * `sort` is STOREFRONT-WIDE, across providers, because the merchant thinks "card first, then valU,
 * then cash" — an ordering of methods that happens to cross contracts.
 */
class StorefrontPaymentMethod extends Model implements TranslatableContract
{
    use Translatable;

    protected $table = 'storefront_payment_methods';

    /** @var list<string> */
    public array $translatedAttributes = ['label'];

    /** @var list<string> */
    protected $fillable = [
        'storefront_payment_provider_id', 'method', 'integration_id', 'icon', 'is_enabled', 'sort', 'settings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sort' => 'integer',
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<StorefrontPaymentProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(StorefrontPaymentProvider::class, 'storefront_payment_provider_id');
    }
}
