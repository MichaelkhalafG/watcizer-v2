<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Notifications\MailBudget;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * "It's back in stock" (2026-10-01) — one product, in the shopper's language, with the other
 * language's line beneath it like the order e-mails, a button to the product, and a link that
 * stops this alert. BULK mail: the header marks it so it counts against the bulk budget only.
 */
final class StockAlertMail extends Mailable
{
    /** @param  array{email: string, locale: string, token: string, product: array{name: string, price: float, image: ?string, url: string}}  $data */
    public function __construct(private readonly array $data) {}

    public function build(): self
    {
        $ar = $this->data['locale'] === 'ar';
        $this->withSymfonyMessage(function (Email $message): void {
            $message->getHeaders()->addTextHeader(MailBudget::BULK_HEADER, MailBudget::BULK);
        });

        return $this
            ->subject($ar ? '🔔 عاد متوفراً — '.$this->data['product']['name'] : '🔔 Back in stock — '.$this->data['product']['name'])
            ->view('emails.stock-alert', [
                'ar' => $ar,
                'product' => $this->data['product'],
                'stopUrl' => config()->string('notifications.stock_alerts.public_url').'/stock-alerts/stop/'.$this->data['token'],
            ]);
    }
}
