<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class SubType extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['sub_type_name'];
    protected $fillable = ['image'];

    /**
     * Expose an absolute, resolvable image URL in every API response.
     *
     * The `image` column stores only a bare filename (e.g. "169_abc.webp") saved
     * under public/Uploads_Images/Sub_type/. AllSubType returns SubType::all()
     * raw, so the frontend previously received just the filename and resolved it
     * with its DEFAULT "Product" folder → /Uploads_Images/Product/… → 404, which
     * is why sub-type images showed in the admin but never on the storefront.
     * Appending image_url gives the Next.js side a ready-to-use absolute URL.
     */
    protected $appends = ['image_url'];

    public function product()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Absolute URL for the sub-type image, or null when none is set.
     * Mirrors the idempotent logic in ProductResource / imageUrl.js: full URLs
     * pass through, a value already carrying a folder segment is used as-is under
     * Uploads_Images, and a bare filename gets the correct Sub_type folder.
     */
    public function getImageUrlAttribute(): ?string
    {
        $file = $this->attributes['image'] ?? null;
        if (! $file) {
            return null;
        }
        if (preg_match('#^https?://#i', $file)) {
            return $file;
        }

        $base = rtrim((string) config('services.asset_base'), '/');
        $file = ltrim($file, '/');
        if (str_contains($file, '/')) {
            return $base . '/Uploads_Images/' . $file;
        }

        return $base . '/Uploads_Images/Sub_type/' . $file;
    }
}
