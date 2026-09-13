<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;

/**
 * `storefront_payment_method_translations` — astrotomic pattern (no timestamps, UNIQUE(fk, locale)).
 *
 * The label the CUSTOMER reads, per locale, per storefront. Not for telling two providers apart:
 * the customer only ever sees one entry per method key (study §3.9.5), and when two enabled rows
 * share a key with DIFFERENT labels the dashboard warns, because re-ordering them would otherwise
 * change the storefront's wording silently.
 */
class StorefrontPaymentMethodTranslation extends Model
{
    protected $table = 'storefront_payment_method_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'label'];
}
