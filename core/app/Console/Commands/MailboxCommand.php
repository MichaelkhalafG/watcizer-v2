<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\StorefrontMailer;
use App\Models\Storefront\StorefrontMailbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * mail:mailbox — write or update a storefront's mailbox row (W3, 2026-10-13), for the first
 * production run and for a password rotation; the dashboard screen does the same afterwards.
 *
 *   php artisan mail:mailbox watchizer --host=smtp.hostinger.com --port=465 --encryption=ssl \
 *       --username=noreply@watchizereg.com --from=noreply@watchizereg.com --from-name=Watchizer \
 *       --recipients=a@x.com,b@x.com --cap=800 --reserve=200 --password-env=WZ_MAIL_PW
 *
 * THE PASSWORD IS NEVER AN ARGUMENT. `--password-env` names an environment variable; the value is
 * read from it and never echoed, so it is not in the shell history, the process list or this
 * command's output. Set it for one command only: `WZ_MAIL_PW='…' php artisan mail:mailbox …`
 * (the leading space / `HISTCONTROL=ignorespace` keeps even that line out of bash history).
 * Options not given keep the row's current value; a new row needs them all.
 *
 *   php artisan mail:mailbox watchizer --show      # the row, with the password shown as set / not set
 */
final class MailboxCommand extends Command
{
    protected $signature = 'mail:mailbox
        {code : the storefront code (watchizer, brandfashion)}
        {--show : print the row (never the password) and change nothing}
        {--host= : SMTP host}
        {--port= : SMTP port (465 for ssl, 587 for tls)}
        {--encryption= : tls | ssl | none}
        {--username= : SMTP login}
        {--password-env= : NAME of the environment variable holding the SMTP password}
        {--from= : from address}
        {--from-name= : from name}
        {--recipients= : comma-separated team addresses that receive the admin copy of an order}
        {--cap= : daily cap for this mailbox}
        {--reserve= : of the cap, how many are kept for order and account mail}
        {--enable : enable the mailbox}
        {--disable : disable the mailbox (its mail waits with a reason; nothing falls back)}';

    protected $description = 'Write or update a storefront\'s mailbox row (SMTP, envelope, budget, order recipients); the password from an env NAME';

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        $storefrontId = DB::table('storefronts')->where('code', $code)->value('id');
        if (! is_numeric($storefrontId)) {
            $this->error("no storefront with code [{$code}]");

            return self::FAILURE;
        }
        $storefrontId = (int) $storefrontId;
        $mailbox = StorefrontMailbox::query()->firstOrNew(['storefront_id' => $storefrontId]);

        if ((bool) $this->option('show')) {
            $this->show($mailbox);

            return self::SUCCESS;
        }

        $fields = ['host' => 'host', 'port' => 'port', 'encryption' => 'encryption', 'username' => 'username',
            'from' => 'from_address', 'from-name' => 'from_name', 'cap' => 'daily_cap', 'reserve' => 'transactional_reserve'];
        foreach ($fields as $option => $column) {
            $value = $this->option($option);
            if (is_string($value) && $value !== '') {
                $mailbox->setAttribute($column, in_array($column, ['port', 'daily_cap', 'transactional_reserve'], true) ? (int) $value : $value);
            }
        }
        $recipients = $this->option('recipients');
        if (is_string($recipients)) {
            $mailbox->setAttribute('order_recipients', array_values(array_filter(array_map('trim', explode(',', $recipients)), fn (string $a): bool => $a !== '')));
        }
        $passwordEnv = $this->option('password-env');
        if (is_string($passwordEnv) && $passwordEnv !== '') {
            $password = getenv($passwordEnv);
            if (! is_string($password) || $password === '') {
                $this->error("environment variable {$passwordEnv} is not set or empty; the password is never passed as an argument");

                return self::FAILURE;
            }
            $mailbox->setAttribute('credentials', ['password' => $password]);
        }
        if ((bool) $this->option('enable')) {
            $mailbox->setAttribute('is_enabled', true);
        }
        if ((bool) $this->option('disable')) {
            $mailbox->setAttribute('is_enabled', false);
        }
        if (! $mailbox->exists) {
            $mailbox->setAttribute('is_enabled', $mailbox->getAttribute('is_enabled') ?? true);
            $mailbox->setAttribute('encryption', $mailbox->getAttribute('encryption') ?? 'tls');
            $mailbox->setAttribute('port', $mailbox->getAttribute('port') ?? 587);
            $mailbox->setAttribute('daily_cap', $mailbox->getAttribute('daily_cap') ?? 800);
            $mailbox->setAttribute('transactional_reserve', $mailbox->getAttribute('transactional_reserve') ?? 200);
        }

        $missing = $mailbox->missing(needsTransport: true);
        if ($missing !== []) {
            $this->warn('saved, but NOT usable for a real send yet — missing: '.implode(', ', $missing));
        }
        $mailbox->save();
        StorefrontMailer::forget($storefrontId);
        $this->show($mailbox);

        return self::SUCCESS;
    }

    private function show(StorefrontMailbox $mailbox): void
    {
        $this->line(sprintf(
            'storefront %d · %s · %s:%d %s · username %s · from "%s" <%s> · password %s · cap %d · reserve %d · order recipients: %s',
            $mailbox->storefrontId(),
            $mailbox->exists ? ($mailbox->isEnabled() ? 'enabled' : 'DISABLED') : 'NO ROW',
            $mailbox->host(), $mailbox->port(), $mailbox->encryption(),
            $mailbox->username(),
            $mailbox->fromName(), $mailbox->fromAddress(),
            $mailbox->credentialsSet() ? 'set' : 'NOT SET',
            $mailbox->dailyCap(), $mailbox->transactionalReserve(),
            implode(', ', $mailbox->orderRecipients()) ?: '(none — admin order mail will fail visibly)',
        ));
    }
}
