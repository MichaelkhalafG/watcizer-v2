<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Translation rows for Grade (`catalog_grade_translations`), astrotomic pattern: no timestamps, UNIQUE(fk, locale).
 */
class GradeTranslation extends Model
{
    protected $table = 'catalog_grade_translations';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['locale', 'name', 'description'];
}
