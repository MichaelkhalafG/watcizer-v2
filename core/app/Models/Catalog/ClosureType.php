<?php

namespace App\Models\Catalog;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Master-data table `catalog_closure_types` (CLEAN_CORE_STUDY §2.5); translated names live in `ClosureTypeTranslation`.
 */
class ClosureType extends Model implements TranslatableContract
{
    use Translatable;

    protected $table = 'catalog_closure_types';

    /** @var list<string> */
    public array $translatedAttributes = ['name'];

    /** @var list<string> */
    protected $fillable = [];
}
