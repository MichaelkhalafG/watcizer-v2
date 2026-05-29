<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class Shape extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['shape_name'];
    protected $fillable = [];

    public function product()
    {
        return $this->hasMany(Product::class , 'case_shape_id' , 'id');
    }
}
