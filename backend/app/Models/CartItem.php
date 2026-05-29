<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'offer_id',
        'quantity',
        'piece_price',
        'total_price',
        'type_stock',
        'color_band',
        'color_dial',
    ];

    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    public function product()
    {
        return $this->hasMany(Product::class);
    }
}
