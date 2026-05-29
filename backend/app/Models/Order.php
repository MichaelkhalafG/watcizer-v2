<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'address_id',
        'total_price_for_order',
        'status',
        'payment_method',
        'order_number',
        'note',
        'guest_name',   // ← جديد
        'guest_email',  // ← جديد
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function address()
    {
        return $this->hasOne(Address::class, 'id', 'address_id');
    }

    public function order_item()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paymentStatus()
    {
        return $this->hasOne(PaymentStatus::class, 'order_id', 'id');
    }
}