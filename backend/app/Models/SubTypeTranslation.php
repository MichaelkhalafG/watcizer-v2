<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubTypeTranslation extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $fillable = ['sub_type_name'];
}
