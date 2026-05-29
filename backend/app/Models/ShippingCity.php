<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;

class ShippingCity extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['city_name'];
    protected $fillable = ['shipping_cost'];

    public function address()
    {
        return $this->hasMany(Address::class);
    }
}
