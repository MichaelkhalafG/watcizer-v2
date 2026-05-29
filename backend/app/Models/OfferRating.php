<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfferRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'offer_id',
        'rating',
        'comment',
    ];

    public function offer() {
        return $this->hasOne(Offer::class , 'id' , 'offer_id');
    }

    public function user() {
        return $this->hasOne(User::class , 'id' , 'user_id');
    }
}
