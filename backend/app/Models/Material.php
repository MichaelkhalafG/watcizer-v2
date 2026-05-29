<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;

class Material extends Model implements TranslatableContract
{
    use HasFactory , Translatable;

    public $translatedAttributes = ['material_name'];
    protected $fillable = [];

    public function product($foreignKey)
    {
        return $this->hasMany(Product::class , $foreignKey);
    }

    public function productBandMaterial()
    {
        return $this->product('band_material_id');
    }

    public function productDialCaseMaterial()
    {
        return $this->product('dial_case_material_id');
    }

    public function productDialGlassMaterial()
    {
        return $this->product('dial_glass_material_id');
    }
}
