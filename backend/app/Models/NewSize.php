<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewSize extends Model
{
    protected $table = 'new_sizes';

    protected $fillable = [
        'name_en', 'name_ar', 'type', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Types: clothing / shoes / watch / general
    const TYPES = [
        'clothing' => 'Clothing (XS-XXXXXL)',
        'shoes'    => 'Shoes (26-47)',
        'watch'    => 'Watch (mm/ATM)',
        'general'  => 'General',
    ];

    public function variants()
    {
        return $this->hasMany(ProductVariant::class, 'size_id');
    }

    public function getName(string $locale = 'en'): string
    {
        return $locale === 'ar' ? $this->name_ar : $this->name_en;
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}