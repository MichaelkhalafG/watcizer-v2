<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * `catalog_product_translations` — one row per (product, locale); `title` is mandatory.
 */
class ProductTranslation extends Model
{
    protected $table = 'catalog_product_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'locale', 'title', 'short_description', 'long_description', 'model_name', 'country', 'stone',
        'meta_title', 'meta_description',
    ];
}
