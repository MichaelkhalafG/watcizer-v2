<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;

class Feature extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['feature_name'];
    protected $fillable = [];

    public function product()
    {
        return $this->belongsToMany(Product::class);
    }
}
