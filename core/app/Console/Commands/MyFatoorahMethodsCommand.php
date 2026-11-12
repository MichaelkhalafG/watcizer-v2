<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payment\MyFatoorahProvider;
use App\Domain\Payment\ProviderCredentials;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontPaymentProvider;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan payments:myfatoorah-methods brandfashion` — READ ONLY (2026-11-12, Block B).
 *
 * Asks the storefront's MyFatoorah account which methods it offers NOW (InitiatePayment — nothing is created), with
 * each method's code: the code is what a dashboard method row's integration id holds. Then it checks the two things
 * that would make a payment fail on the day: the account invoicing in a currency other than the order's (the amount
 * check would refuse every payment), and a dashboard row whose code the account does not offer.
 */
final class MyFatoorahMethodsCommand extends Command
{
    protected $signature = 'payments:myfatoorah-methods
        {storefront : the storefront code}
        {--amount=100 : an amount to ask about, in EGP}
        {--payment= : instead: show what MyFatoorah says about ONE payment id (the first live test payment: its currency)}';

    protected $description = 'MyFatoorah: list the methods the storefront\'s account offers, and check the dashboard rows against them (read only)';

    public function handle(MyFatoorahProvider $provider): int
    {
        $storefront = Storefront::query()->where('code', Coerce::str($this->argument('storefront')))->first();
        if (! $storefront instanceof Storefront) {
            $this->error('No such storefront.');

            return self::FAILURE;
        }
        $contract = StorefrontPaymentProvider::query()->where('storefront_id', $storefront->id)->where('provider', MyFatoorahProvider::KEY)->first();
        if ($contract === null) {
            $this->error('This storefront has no MyFatoorah contract (dashboard → Payments → add MyFatoorah).');

            return self::FAILURE;
        }
        if (MyFatoorahProvider::base() === '') {
            $this->error('MYFATOORAH_BASE_URL is not set in core\'s .env.');

            return self::FAILURE;
        }
        $credentials = ProviderCredentials::fromArray(Coerce::arr($contract->getAttribute('credentials')));
        $payment = Coerce::str($this->option('payment'));
        if ($payment !== '') {
            $status = $provider->statusOf($credentials, $payment);
            if ($status === null) {
                $this->error('MyFatoorah did not answer for that payment id.');

                return self::FAILURE;
            }
            foreach (['InvoiceId', 'InvoiceStatus', 'InvoiceValue', 'InvoiceDisplayValue', 'CustomerReference'] as $k) {
                $this->line(sprintf('  %-20s %s', $k, Coerce::str($status[$k] ?? null)));
            }
            $this->line('  The account currency is the one InvoiceDisplayValue names: it must be EGP (an Egyptian account).');

            return self::SUCCESS;
        }
        $methods = $provider->methods($credentials, (float) Coerce::str($this->option('amount'), '100'), 'EGP');
        if ($methods === null) {
            $this->error('MyFatoorah did not answer (no API key on the contract, a wrong key for '.MyFatoorahProvider::base().', or the host is unreachable).');

            return self::FAILURE;
        }

        $this->info('MyFatoorah at '.MyFatoorahProvider::base().' — '.count($methods).' methods enabled on the account:');
        $byCode = [];
        foreach ($methods as $m) {
            $code = Coerce::str($m['PaymentMethodCode'] ?? null);
            $byCode[strtolower($code)][] = Coerce::str($m['PaymentMethodId'] ?? null);
            $this->line(sprintf('  %-8s id %-4s %-28s %s', $code, Coerce::str($m['PaymentMethodId'] ?? null), Coerce::str($m['PaymentMethodEn'] ?? null), Coerce::str($m['CurrencyIso'] ?? null)));
        }
        // a code the account lists more than once can only be routed by its method ID (2026-11-12: vm = 14 and 2)
        foreach ($byCode as $code => $ids) {
            if (count($ids) > 1) {
                $this->warn(sprintf('  WARNING: the code %s is listed %d times (ids %s) — a dashboard row must use the method ID, not the code.', $code, count($ids), implode(', ', $ids)));
            }
        }

        $failures = 0;
        $rows = DB::table('storefront_payment_methods')->where('storefront_payment_provider_id', $contract->getKey())->orderBy('sort')
            ->get(['id', 'method', 'integration_id', 'is_enabled']);
        $this->line('  dashboard rows on this contract (each checked by the same rule as the checkout, MyFatoorahProvider::pick):');
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $integration = trim(Row::nstr($row, 'integration_id') ?? '');
            $picked = $integration === '' ? ['method' => null, 'problem' => 'no integration id'] : MyFatoorahProvider::pick($methods, $integration);
            $ok = $picked['method'] !== null;
            $on = Row::bool($row, 'is_enabled');
            $this->line(sprintf('    #%-6s %-12s id/code %-8s %s %s', (string) Row::int($row, 'id'), Row::str($row, 'method'), $integration === '' ? '-' : $integration,
                $on ? 'ON ' : 'off', $ok
                    ? sprintf('✓ → id %s %s', Coerce::str($picked['method']['PaymentMethodId'] ?? null), Coerce::str($picked['method']['PaymentMethodEn'] ?? null))
                    : '✗ '.($picked['problem'] ?? 'NOT offered by the account')));
            if (! $ok && $on) {
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
