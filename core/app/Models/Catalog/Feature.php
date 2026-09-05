<?php

namespace App\Models\Catalog;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Master-data table `catalog_features` (CLEAN_CORE_STUDY §2.5); translated names live in `FeatureTranslation`.
 */
class Feature extends Model implements TranslatableContract
{
    use Translatable;

    protected $table = 'catalog_features';

    /** @var list<string> */
    public array $translatedAttributes = ['name'];

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'catalog_product_feature', 'feature_id', 'product_id');
    }
}
