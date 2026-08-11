<?php

namespace App\Observers;

use App\Models\Offer;
use App\Models\Product;
use App\Models\WishlistItem;
use App\Mail\WishlistRestockMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ProductObserver
{
    /**
     * Fires just before a product is deleted. Prune this product's id from every
     * offer's `gift_product_ids` JSON array so offers never point at a product
     * that no longer exists. Runs inside the same request as the delete; if the
     * delete is wrapped in a transaction this cleanup rolls back with it.
     */
    public function deleting(Product $product): void
    {
        $offers = Offer::whereJsonContains('gift_product_ids', $product->id)
            ->orWhereJsonContains('gift_product_ids', (string) $product->id)
            ->get();

        foreach ($offers as $offer) {
            // gift_product_ids is array-cast on the model, but guard against a
            // raw JSON string / null in case the cast is ever removed.
            $ids = $offer->gift_product_ids;
            if (is_string($ids)) {
                $ids = json_decode($ids, true) ?: [];
            }
            if (! is_array($ids)) {
                continue;
            }

            // Drop the deleted id (compare loosely so '5' and 5 both match).
            $filtered = array_values(array_filter($ids, function ($id) use ($product) {
                return (int) $id !== (int) $product->id;
            }));

            if (count($filtered) !== count($ids)) {
                $offer->gift_product_ids = $filtered;
                $offer->save();
            }
        }
    }

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
