<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class ProductImage extends Model
{
    use HasFactory;
    protected $fillable = [
        'product_id',
        'image',
        'is_cover',
        'sort',
        'alt_ar',
        'alt_en',
    ];
    protected $casts = [
        'is_cover' => 'boolean',
    ];
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort')->orderBy('id');
    }
    public function scopeCover($query)
    {
        return $query->where('is_cover', true);
    }
    public function getUrlAttribute(): string
    {
        return asset('Uploads_Images/Product_image/' . $this->image);
    }
}