<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Notifications\MailBudget;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * To the TEAM, 24 hours before a re-engagement run goes out (2026-10-01): who it goes to (the team
 * or customers), how many, when, how to stop it, and one sample exactly as a recipient will see it.
 */
final class ReEngagementPreviewMail extends Mailable
{
    /** @param  array{week: string, audience: string, recipients: int, send_after: string, sample: array<string, mixed>|null}  $data */
    public function __construct(private readonly array $data, private readonly string $manageUrl) {}

    public function build(): self
    {
        $this->withSymfonyMessage(function (Email $message): void {
            $message->getHeaders()->addTextHeader(MailBudget::BULK_HEADER, MailBudget::BULK);
        });

        return $this
            ->subject('معاينة رسالة الأسبوع '.$this->data['week'].' — Re-engagement preview')
            ->view('emails.reengagement-preview', $this->data + ['manageUrl' => $this->manageUrl]);
    }
}
