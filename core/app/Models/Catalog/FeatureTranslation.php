<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for Feature (`catalog_feature_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class FeatureTranslation extends Model
{
    protected $table = 'catalog_feature_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
