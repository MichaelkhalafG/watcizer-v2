<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\WishlistItem;
use App\Mail\WishlistRestockMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ProductObserver
{
    /**
     * Fires on Eloquent model updates (e.g. an admin restocking a product).
     * NOTE: the order flow adjusts stock with query-builder increment()/
     * decrement(), which bypass model events — so this only reacts to genuine
     * catalog updates, exactly the case we want to notify on.
     */
    public function updated(Product $product): void
    {
        // Only care when a stock column actually changed.
        if (!$product->wasChanged(['stock', 'market_stock'])) {
            return;
        }

        $oldStock       = (int) $product->getOriginal('stock');
        $oldMarketStock = (int) $product->getOriginal('market_stock');
        $newStock       = (int) $product->stock;
        $newMarketStock = (int) $product->market_stock;

        $wasOutOfStock = ($oldStock <= 0 && $oldMarketStock <= 0);
        $nowInStock    = ($newStock > 0 || $newMarketStock > 0);

        $wasInStock     = ($oldStock > 0 || $oldMarketStock > 0);
        $nowOutOfStock  = ($newStock <= 0 && $newMarketStock <= 0);

        if ($wasOutOfStock && $nowInStock) {
            $this->notifyWishlistUsers($product);
        } elseif ($wasInStock && $nowOutOfStock) {
            // Product sold out again → clear the flags so the next restock
            // notifies wishlist users a second time.
            WishlistItem::where('product_id', $product->id)
                ->whereNotNull('notified_at')
                ->update(['notified_at' => null]);
        }
    }

    /**
     * Queue a restock email to every user who has this product in their
     * wishlist and hasn't already been notified for the current out-of-stock
     * cycle.
     */
    private function notifyWishlistUsers(Product $product): void
    {
        // Eager-load the brand once so the template never lazy-loads per email.
        $product->loadMissing(['brand.translations', 'translations']);

        WishlistItem::where('product_id', $product->id)
            ->whereNull('notified_at')
            ->with('wishlist.user')
            ->chunkById(200, function ($items) use ($product) {
                foreach ($items as $item) {
                    $user = $item->wishlist?->user;
                    if (!$user || !$user->email) {
                        continue;
                    }
                    try {
                        Mail::to($user->email)->queue(new WishlistRestockMail($product, $user));
                        $item->update(['notified_at' => now()]);
                    } catch (\Throwable $e) {
                        Log::error('Restock email failed: ' . $e->getMessage());
                    }
                }
            });
    }
}
