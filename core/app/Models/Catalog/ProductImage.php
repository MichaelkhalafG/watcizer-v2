<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `catalog_product_images` — `path` is relative to Uploads_Images/; `renditions`
 * holds the generated responsive files (CLEAN_CORE_STUDY §5.4).
 */
class ProductImage extends Model
{
    protected $table = 'catalog_product_images';

    /** @var list<string> */
    protected $fillable = ['product_id', 'path', 'is_cover', 'sort', 'width', 'height', 'alt_en', 'alt_ar', 'renditions'];

    /**
     * The photo's background mask (2026-11-05) is the storefront's, read by `CutMasks` alone: ~1.7 KB of base64
     * that no screen and no payload built from this model has a use for.
     *
     * @var list<string>
     */
    protected $hidden = ['cut_mask', 'cut_mask_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_cover' => 'boolean',
            'sort' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'renditions' => 'array',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
