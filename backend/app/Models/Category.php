<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Illuminate\Support\Str;

class Category extends Model implements TranslatableContract
{
    use HasFactory, Translatable;

    // ── Translatable ────────────────────────────────────────
    public $translatedAttributes = ['name', 'description'];

    // ── Fillable ────────────────────────────────────────────
    protected $fillable = [
        'parent_id',
        'level',
        'slug',
        'image',
        'is_active',
        'sort_order',
    ];

    // ── Casts ───────────────────────────────────────────────
    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ═══════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ═══════════════════════════════════════════════════════

    /** Parent category (null for main categories) */
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** Direct children only */
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')
                    ->orderBy('sort_order')
                    ->orderBy('id');
    }

    /** All descendants recursively */
    public function allChildren()
    {
        return $this->children()->with('allChildren');
    }

    /** Products linked via main_category_id */
    public function mainProducts()
    {
        return $this->hasMany(Product::class, 'main_category_id');
    }

    /** Products linked via sub_category_id */
    public function subProducts()
    {
        return $this->hasMany(Product::class, 'sub_category_id');
    }

    /** Products linked via product_type_id */
    public function typeProducts()
    {
        return $this->hasMany(Product::class, 'product_type_id');
    }

    // ═══════════════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════════════

    /** Only top-level (main) categories */
    public function scopeMain($query)
    {
        return $query->whereNull('parent_id')->where('level', 1);
    }

    /** Only sub-categories (level 2) */
    public function scopeSubCategories($query)
    {
        return $query->where('level', 2);
    }

    /** Only product types (level 3) */
    public function scopeProductTypes($query)
    {
        return $query->where('level', 3);
    }

    /** Active only */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Children of a specific parent */
    public function scopeChildrenOf($query, int $parentId)
    {
        return $query->where('parent_id', $parentId);
    }

    // ═══════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════

    /**
     * Auto-generate slug from English name if not provided.
     */
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->translateOrNew('en')->name ?? 'category-' . time());
            }
            // Auto-set level from parent
            if ($model->parent_id) {
                $parent = static::find($model->parent_id);
                $model->level = $parent ? $parent->level + 1 : 1;
            }
        });
    }

    /** Full breadcrumb path: Main > Sub > Type */
    public function getBreadcrumbAttribute(): string
    {
        $parts = [];
        $node  = $this;
        while ($node) {
            array_unshift($parts, $node->name);
            $node = $node->parent;
        }
        return implode(' > ', $parts);
    }

    /** Level label for display */
    public function getLevelLabelAttribute(): string
    {
        return match ($this->level) {
            1 => 'Main Category',
            2 => 'Sub Category',
            3 => 'Product Type',
            default => 'Category',
        };
    }
}