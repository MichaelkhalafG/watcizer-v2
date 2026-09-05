<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for MovementType (`catalog_movement_type_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class MovementTypeTranslation extends Model
{
    protected $table = 'catalog_movement_type_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
