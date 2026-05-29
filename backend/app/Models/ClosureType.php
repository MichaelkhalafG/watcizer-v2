<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;

class ClosureType extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['closure_type_name'];
    protected $fillable = [];

    public function product()
    {
        return $this->hasMany(Product::class , 'band_closure_id' , 'id');
    }
}
