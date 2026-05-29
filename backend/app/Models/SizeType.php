<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;

class SizeType extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['size_type_name'];
    protected $fillable = [];

    public function product($foreignKey)
    {
        return $this->hasMany(Product::class , $foreignKey);
    }

    public function caseSizeTypeProduct()
    {
        return $this->product('case_size_type_id');
    }

    public function bandSizeTypeProduct()
    {
        return $this->product('band_size_type_id');
    }

    public function waterResistanceSizeTypeProduct()
    {
        return $this->product('water_resistance_size_type_id');
    }

    public function bandWidthSizeTypeProduct()
    {
        return $this->product('band_width_size_type_id');
    }

    public function caseThicknessSizeTypeProduct()
    {
        return $this->product('case_thickness_size_type_id');
    }

    public function watchHeightSizeTypeProduct()
    {
        return $this->product('watch_height_size_type_id');
    }

    public function watchWidthSizeTypeProduct()
    {
        return $this->product('watch_width_size_type_id');
    }

    public function watchLengthSizeTypeProduct()
    {
        return $this->product('watch_length_size_type_id');
    }
}
