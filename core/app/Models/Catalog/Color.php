<?php

namespace App\Models\Catalog;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master-data table `catalog_colors` (CLEAN_CORE_STUDY §2.5); translated names live in `ColorTranslation`.
 */
class Color extends Model implements TranslatableContract
{
    use Translatable;

    protected $table = 'catalog_colors';

    /** @var list<string> */
    public array $translatedAttributes = ['name'];

    /** @var list<string> */
    protected $fillable = ['hex'];

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'catalog_product_color', 'color_id', 'product_id')->withPivot('role');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'color_id');
    }
}
