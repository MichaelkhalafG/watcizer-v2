<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShapeTranslation extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $fillable = ['shape_name'];
}
