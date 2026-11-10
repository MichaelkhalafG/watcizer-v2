<?php

declare(strict_types=1);

namespace App\Mail;

use App\Storefront\StorefrontIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;

/**
 * "Your friend's order arrived — here is your reward" (the wheel's invite-a-friend prize, 2026-11-09).
 *
 * Sent to the WINNER'S ACCOUNT e-mail (the account that activated the invite; Michael, 2026-11-09 evening) when a
 * friend's first order with their invite is DELIVERED. The shop's own e-mail layout (the wordmark bar, the footer with
 * the shop's WhatsApp — StorefrontIdentity, the storefront row), both languages in one message, the spin's language
 * first. Not queued — sent synchronously by StorefrontMailer from the storefront's own mailbox, as every customer mail is.
 */
final class WheelReferralReward extends Mailable
{
    public function __construct(
        private readonly string $code,
        private readonly float $percent,
        private readonly Carbon $expiresAt,
        private readonly string $lang,
        private readonly StorefrontIdentity $identity,
    ) {}

    public function build(): self
    {
        $percent = rtrim(rtrim(number_format($this->percent, 2, '.', ''), '0'), '.');
        $ar = $this->lang === 'ar';

        return $this
            ->subject($ar ? "مكافأة دعوتك: خصم {$percent}٪" : "Your invite reward: {$percent}% off")
            ->view('emails.wheel-referral-reward', [
                'ar' => $ar,
                'code' => $this->code,
                'percent' => $percent,
                'untilEn' => $this->expiresAt->copy()->timezone('Africa/Cairo')->format('j M Y, H:i'),
                'untilAr' => $this->expiresAt->copy()->timezone('Africa/Cairo')->format('Y-m-d H:i'),
            ] + $this->identity->viewData());
    }
}
