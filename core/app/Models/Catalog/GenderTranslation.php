<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for Gender (`catalog_gender_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class GenderTranslation extends Model
{
    protected $table = 'catalog_gender_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];
}
