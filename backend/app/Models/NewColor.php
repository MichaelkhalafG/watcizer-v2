<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewColor extends Model
{
    protected $table = 'new_colors';

    protected $fillable = [
        'name_en', 'name_ar', 'hex', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function variants()
    {
        return $this->hasMany(ProductVariant::class, 'color_id');
    }

    // Helper — اسم حسب اللغة
    public function getName(string $locale = 'en'): string
    {
        return $locale === 'ar' ? $this->name_ar : $this->name_en;
    }
}