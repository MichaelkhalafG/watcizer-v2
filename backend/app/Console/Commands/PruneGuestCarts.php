<?php

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;

class PruneGuestCarts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'carts:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired guest carts (and their items).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;

        Cart::whereNotNull('guest_token')
            ->where('expires_at', '<', now())
            ->each(function (Cart $cart) use (&$count) {
                $cart->cart_item()->delete();
                $cart->delete();
                $count++;
            });

        $this->info("Pruned {$count} expired guest carts.");
        return Command::SUCCESS;
    }
}
