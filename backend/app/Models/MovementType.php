<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class MovementType extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['movement_type_name'];
    protected $fillable = [];

    public function product()
    {
        return $this->hasMany(Product::class , 'watch_movement_id' , 'id');
    }
}
