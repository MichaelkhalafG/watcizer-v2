<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;

class DisplayType extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['display_type_name'];
    protected $fillable = [];

    public function product()
    {
        return $this->hasMany(Product::class , 'dial_display_type_id' , 'id');
    }
}
