<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for Brand (`catalog_brand_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class BrandTranslation extends Model
{
    protected $table = 'catalog_brand_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
