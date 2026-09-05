<?php

namespace App\Models\Catalog;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master-data table `catalog_grades` (CLEAN_CORE_STUDY §2.5); translated names live in `GradeTranslation`.
 */
class Grade extends Model implements TranslatableContract
{
    use Translatable;

    protected $table = 'catalog_grades';

    /** @var list<string> */
    public array $translatedAttributes = ['name', 'description'];

    /** @var list<string> */
    protected $fillable = ['image_path'];

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'grade_id');
    }
}
