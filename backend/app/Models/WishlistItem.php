<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WishlistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'offer_id',
    ];

    public function wishlist()
    {
        return $this->hasOne(Wishlist::class);
    }

    public function product()
    {
        return $this->hasMany(Product::class);
    }
}
