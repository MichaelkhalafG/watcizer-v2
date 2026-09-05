<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for Unit (`catalog_unit_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class UnitTranslation extends Model
{
    protected $table = 'catalog_unit_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
