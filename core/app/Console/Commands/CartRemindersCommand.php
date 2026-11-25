<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\CartReminders;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * cart-reminders run - the abandoned-cart e-mail, hourly (2026-11-24, CartReminders). For every shop whose switch is ON:
 * each cart due now gets one row and, unless a rule says no, its e-mail in the bulk outbox (sent by bulk-mail:drain
 * within the shop's bulk allowance). WRITES `core_cart_reminders` and outbox rows; e-mails only real carts' owners, and
 * only on a shop the team switched on. --dry-run counts what is due per shop and writes nothing.
 */
final class CartRemindersCommand extends Command
{
    protected $signature = 'cart-reminders {action=run : run} {--dry-run : count the carts due per shop, write nothing}';

    protected $description = 'Queue the abandoned-cart e-mails that are due (hourly)';

    public function handle(BulkMailer $mailer): int
    {
        if ((bool) $this->option('dry-run')) {
            foreach (DB::table('storefronts')->where('is_active', 1)->pluck('id') as $raw) {
                $sf = Coerce::int($raw);
                $this->line(sprintf('shop %d: switch %s, %d cart(s) due', $sf, CartReminders::settings($sf)['enabled'] ? 'ON' : 'off', count(CartReminders::due($sf))));
            }

            return self::SUCCESS;
        }
        $out = CartReminders::run($mailer);
        $this->line(sprintf('cart-reminders: %d queued, %d skipped (with the reason on each row).', $out['queued'], $out['skipped']));

        return self::SUCCESS;
    }
}
