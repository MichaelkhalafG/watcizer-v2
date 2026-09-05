<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for Size (`catalog_size_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class SizeTranslation extends Model
{
    protected $table = 'catalog_size_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
