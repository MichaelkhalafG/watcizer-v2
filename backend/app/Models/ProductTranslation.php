<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductTranslation extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $fillable = [
        'product_title',
        'model_name',
        'country',
        'stone',
        'long_description',
        'short_description',
    ];
}
