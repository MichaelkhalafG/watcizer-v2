<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for ClosureType (`catalog_closure_type_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class ClosureTypeTranslation extends Model
{
    protected $table = 'catalog_closure_type_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
