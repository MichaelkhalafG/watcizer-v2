<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BannerBottom extends Model
{
    use HasFactory;

    protected $fillable = ['image' , 'offer_id'];

    public function offer()
    {
        return $this->belongsTo(Offer::class);
    }
}
