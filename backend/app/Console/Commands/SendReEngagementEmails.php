<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\User;
use App\Mail\ReEngagementMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendReEngagementEmails extends Command
{
    protected $signature = 'emails:re-engagement';
    protected $description = 'Send offers to users inactive for 30+ days';

    public function handle(): int
    {
        // Inactive: never logged in, or last login 30+ days ago — and not
        // already re-engaged in the last 30 days (one email per month max).
        $inactiveUsers = User::query()
            ->whereNotNull('email')
            ->where(function ($q) {
                $q->whereNull('last_login_at')
                  ->orWhere('last_login_at', '<', now()->subDays(30));
            })
            ->where(function ($q) {
                $q->whereNull('last_reengagement_at')
                  ->orWhere('last_reengagement_at', '<', now()->subDays(30));
            })
            ->get();

        $this->info("Found {$inactiveUsers->count()} inactive users");

        if ($inactiveUsers->isEmpty()) {
            $this->info('Nothing to send.');
            return self::SUCCESS;
        }

        $offers = $this->pickOffers();

        if ($offers->isEmpty()) {
            $this->warn('No products available to feature — aborting.');
            return self::SUCCESS;
        }

        foreach ($inactiveUsers as $user) {
            try {
                Mail::to($user->email)->queue(new ReEngagementMail($user, $offers));
                $user->forceFill(['last_reengagement_at' => now()])->save();
                $this->info("Queued: {$user->email}");
            } catch (\Throwable $e) {
                $this->error("Failed for {$user->email}: {$e->getMessage()}");
            }
        }

        $this->info('Re-engagement emails complete');
        return self::SUCCESS;
    }

    /**
     * Best 6 discounted, in-stock products (highest % off first). Falls back to
     * the newest in-stock products when nothing is on sale. Stock is considered
     * available across either the Express (stock) or Market (market_stock) pool.
     */
    private function pickOffers()
    {
        $inStock = fn ($q) => $q->where(function ($s) {
            $s->where('stock', '>', 0)->orWhere('market_stock', '>', 0);
        });

        $offers = Product::query()
            ->where('sale_price_after_discount', '>', 0)
            ->whereColumn('sale_price_after_discount', '<', 'selling_price')
            ->where($inStock)
            ->orderByRaw('(1 - sale_price_after_discount / selling_price) DESC')
            ->with(['brand.translations', 'translations'])
            ->limit(6)
            ->get();

        if ($offers->isEmpty()) {
            $offers = Product::query()
                ->where($inStock)
                ->orderByDesc('id')
                ->with(['brand.translations', 'translations'])
                ->limit(6)
                ->get();
        }

        return $offers;
    }
}
