<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DisplayTypeTranslation extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $fillable = ['display_type_name'];
}
