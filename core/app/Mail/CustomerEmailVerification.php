<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * The e-mail-verification link (storefront Phase 1, piece 4, 2026-09-21).
 *
 * Arabic-first for the same reason as every other customer e-mail (study §6.2.1). See
 * {@see CustomerPasswordReset} for why neither of these is `ShouldQueue`.
 *
 * The link is a temporary SIGNED route, so its signature is computed from this application's
 * `APP_KEY`. A link the LEGACY host mailed can therefore never be verified here, and vice versa —
 * which is correct rather than a compatibility problem: after Phase 2 only one application mails
 * these, and before then only one of the two is the shop a given customer is using.
 */
final class CustomerEmailVerification extends Mailable
{
    public function __construct(
        private readonly string $url,
        private readonly string $name,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('تأكيد بريدك الإلكتروني | Verify your Watchizer email')
            ->view('emails.email-verification', [
                'url' => $this->url,
                'name' => $this->name,
            ]);
    }
}
