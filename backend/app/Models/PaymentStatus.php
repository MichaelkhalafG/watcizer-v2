<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'pay_transaction_id',
        'pay_order_id',
        'amount_cents',
        'success',
    ];
}
