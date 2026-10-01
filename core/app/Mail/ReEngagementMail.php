<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Notifications\MailBudget;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * The weekly "new for you" e-mail (2026-10-01): up to six products at today's price, Arabic first
 * with English beneath like the order e-mails, and a working unsubscribe — as a link in the body
 * and as the List-Unsubscribe / one-click headers mail apps show as their own button. BULK mail.
 */
final class ReEngagementMail extends Mailable
{
    /** @param  array{email: string, storefront_id: int, products: list<array{name: string, price: float, image: ?string, url: string}>}  $data */
    public function __construct(private readonly array $data, private readonly string $unsubscribeUrl) {}

    public function build(): self
    {
        $url = $this->unsubscribeUrl;
        $this->withSymfonyMessage(function (Email $message) use ($url): void {
            $headers = $message->getHeaders();
            $headers->addTextHeader(MailBudget::BULK_HEADER, MailBudget::BULK);
            $headers->addTextHeader('List-Unsubscribe', '<'.$url.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });

        return $this
            ->subject('✨ New picks for you — Watchizer')                     // English only (2026-10-01)
            ->view('emails.reengagement', ['products' => $this->data['products'], 'unsubscribeUrl' => $url]);
    }
}
