<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CategoryType extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['category_type_name'];
    protected $fillable = ['image'];

    public function product()
    {
        return $this->hasMany(Product::class);
    }
}
