<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;

/**
 * `storefront_category_translations` — astrotomic pattern (no timestamps, UNIQUE(fk, locale)).
 */
class StorefrontCategoryTranslation extends Model
{
    protected $table = 'storefront_category_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name', 'description', 'meta_title', 'meta_description'];
}
