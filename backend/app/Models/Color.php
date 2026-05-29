<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;

class Color extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['color_name'];
    protected $fillable = ['color_value'];

    public function product()
    {
        return $this->belongsToMany(Product::class);
    }

    public function productDialColor()
    {
        return $this->product();
    }

    public function productBandColor()
    {
        return $this->product();
    }
}
