<?php

namespace App\Models\Catalog;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master-data table `catalog_sizes` (CLEAN_CORE_STUDY §2.5); translated names live in `SizeTranslation`.
 */
class Size extends Model implements TranslatableContract
{
    use Translatable;

    protected $table = 'catalog_sizes';

    /** @var list<string> */
    public array $translatedAttributes = ['name'];

    /** @var list<string> */
    protected $fillable = ['type', 'sort'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'size_id');
    }
}
