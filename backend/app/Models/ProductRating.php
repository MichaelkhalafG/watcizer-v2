<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'rating',
        'comment',
    ];

    public function product() {
        return $this->hasOne(Product::class , 'id' , 'product_id');
    }

    public function user() {
        return $this->hasOne(User::class , 'id' , 'user_id');
    }
}
