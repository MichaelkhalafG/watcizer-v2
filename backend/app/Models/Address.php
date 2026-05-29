<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',           // nullable — null = guest
        'shipping_city_id',
        'address_line',
        'phone_number_one',
        'phone_number_two',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * The user who owns this address (null for guests).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shippingCity()
    {
        return $this->belongsTo(ShippingCity::class, 'shipping_city_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}