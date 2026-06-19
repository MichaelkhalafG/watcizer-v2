<?php

namespace App\Services;

use App\Models\Cart;
use Illuminate\Support\Facades\DB;

class MergeGuestCart
{
    public function merge(int $userId, string $guestToken): void
    {
        DB::transaction(function () use ($userId, $guestToken) {
            $guestCart = Cart::where('guest_token', $guestToken)
                             ->with('cart_item')
                             ->first();

            if (!$guestCart) return;

            $userCart = Cart::firstOrCreate(
                ['user_id' => $userId],
                ['expires_at' => null]
            );

            foreach ($guestCart->cart_item as $guestItem) {
                $userCart->cart_item()->updateOrCreate(
                    [
                        'product_id' => $guestItem->product_id,
                        'offer_id'   => $guestItem->offer_id,
                        'color_band' => $guestItem->color_band,
                        'color_dial' => $guestItem->color_dial,
                        'type_stock' => $guestItem->type_stock,
                    ],
                    [
                        'quantity'    => DB::raw(
                            "quantity + {$guestItem->quantity}"
                        ),
                        'piece_price' => $guestItem->piece_price,
                        'total_price' => $guestItem->total_price,
                    ]
                );
            }

            $guestCart->cart_item()->delete();
            $guestCart->delete();
        });
    }
}
