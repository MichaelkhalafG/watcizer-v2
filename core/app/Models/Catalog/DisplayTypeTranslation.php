<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for DisplayType (`catalog_display_type_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class DisplayTypeTranslation extends Model
{
    protected $table = 'catalog_display_type_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
