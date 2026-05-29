<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class Offer extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['offer_name' , 'short_description' , 'long_description'];

    protected $fillable = [
        'main_product_id',
        'category_type_id',
        'gift_product_ids',
        'price',
        'image',
        'stock',
        'in_season',
        'wa_code',
        'average_rate',
    ];

    protected $casts = [
        'gift_product_ids' => 'array',
    ];

    public function mainProduct()
    {
        return $this->belongsTo(Product::class, 'main_product_id');
    }

    public function giftProducts()
    {
        $giftProductIds = $this->gift_product_ids;

        $products = Product::whereIn('id', $giftProductIds)->get()->keyBy('id');

        $orderedProducts = collect($giftProductIds)->map(function ($id) use ($products) {
            return $products->get($id);
        });

        return $orderedProducts;
    }

    public function category_type()
    {
        return $this->hasOne(CategoryType::class , 'id' , 'category_type_id');
    }

    public function offer_rating() {
        return $this->hasMany(OfferRating::class);
    }

    public function order_items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
