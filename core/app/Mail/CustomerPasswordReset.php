<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Customers\CustomerMail;
use Illuminate\Mail\Mailable;

/**
 * The password-reset link (storefront Phase 1, piece 4, 2026-09-21).
 *
 * Arabic-first, like every other customer e-mail this application sends — settled 2026-09-16
 * (study §6.2.1) and not a gap: the customers are Egyptian, and `app/Mail/*` is excluded from the
 * operator translation seam by name, because `ManageText::t()` resolves against the OPERATOR's
 * language and nobody's password-reset e-mail should depend on what language a member of staff had
 * their dashboard set to.
 *
 * ── Not `ShouldQueue`, and the link is the whole payload ────────────────────────────────────
 *
 * Same reasoning as `OrderConfirmation`: queues run `sync` on this hosting (AGENTS §2.11), so
 * queueing would change nothing except adding a serialisation step for a URL that expires in an
 * hour. The one thing this class must never do is end up anywhere durable — see
 * {@see CustomerMail}, which is the only sender and which logs a failure by
 * reference, never with the link attached.
 */
final class CustomerPasswordReset extends Mailable
{
    public function __construct(
        private readonly string $url,
        private readonly string $name,
        private readonly int $expiresMinutes,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('إعادة تعيين كلمة المرور | Watchizer Password Reset')
            ->view('emails.password-reset', [
                'url' => $this->url,
                'name' => $this->name,
                'expiresMinutes' => $this->expiresMinutes,
            ]);
    }
}
