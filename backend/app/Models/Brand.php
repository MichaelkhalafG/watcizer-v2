<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class Brand extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['brand_name'];
    protected $fillable = ['image'];

    public function product()
    {
        return $this->hasMany(Product::class);
    }
}
