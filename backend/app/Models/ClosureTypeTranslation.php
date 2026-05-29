<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClosureTypeTranslation extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $fillable = ['closure_type_name'];
}
