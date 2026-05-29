<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $table = 'product_variants';

    protected $fillable = [
        'product_id',
        'color_id',
        'size_id',
        'stock',
        'sku',
        'price_modifier',
        'is_active',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'price_modifier' => 'decimal:2',
    ];

    // ── Relations ─────────────────────────────────────────
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function color()
    {
        return $this->belongsTo(NewColor::class, 'color_id');
    }

    public function size()
    {
        return $this->belongsTo(NewSize::class, 'size_id');
    }

    // ── Helpers ───────────────────────────────────────────
    public function isInStock(): bool
    {
        return $this->stock > 0;
    }

    public function getLabel(string $locale = 'en'): string
    {
        $parts = [];
        if ($this->color) $parts[] = $this->color->getName($locale);
        if ($this->size)  $parts[] = $this->size->getName($locale);
        return implode(' / ', $parts) ?: 'Default';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }
}