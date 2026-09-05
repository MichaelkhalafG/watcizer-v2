<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for Material (`catalog_material_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class MaterialTranslation extends Model
{
    protected $table = 'catalog_material_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
