<?php

namespace App\Mail;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WishlistRestockMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Product $product,
        public User $user,
    ) {}

    public function build()
    {
        // product_title is an Astrotomic translated attribute (falls back to
        // the default locale). Guard against a missing translation row.
        $name = $this->product->product_title ?? 'your item';

        return $this
            ->subject("Good news! {$name} is back in stock")
            ->view('mails.wishlist-restock', [
                'product' => $this->product,
                'user'    => $this->user,
            ]);
    }
}
