<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class Gender extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['gender_name'];
    protected $fillable = [];

    public function product()
    {
        return $this->belongsToMany(Product::class);
    }
}
