<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for Shape (`catalog_shape_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class ShapeTranslation extends Model
{
    protected $table = 'catalog_shape_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
