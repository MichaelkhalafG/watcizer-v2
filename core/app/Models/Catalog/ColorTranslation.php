<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for Color (`catalog_color_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class ColorTranslation extends Model
{
    protected $table = 'catalog_color_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
