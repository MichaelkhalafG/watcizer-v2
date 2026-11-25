<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Notifications\MailBudget;
use App\Storefront\StorefrontIdentity;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * The abandoned-cart e-mail (2026-11-24): BULK mail with a one-click unsubscribe, as a link in the body and as the
 * List-Unsubscribe / List-Unsubscribe-Post headers mail apps show as their own button.
 */
final class CartReminderMail extends Mailable
{
    /** @param  array{storefront_id: int, email: string, products: list<array<string, mixed>>, code: array{code: string, percent: float, cap: float|null, expires_at: string}|null, cart_url: string, unsubscribe_url: string}  $data */
    public function __construct(private readonly array $data, private readonly StorefrontIdentity $identity) {}

    public function build(): self
    {
        $url = $this->data['unsubscribe_url'];
        $this->withSymfonyMessage(function (Email $message) use ($url): void {
            $headers = $message->getHeaders();
            $headers->addTextHeader(MailBudget::BULK_HEADER, MailBudget::BULK);
            $headers->addTextHeader('List-Unsubscribe', '<'.$url.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });

        return $this
            ->subject('تركت شيئًا في سلتك · You left something in your cart - '.$this->identity->brandName) // i18n-exempt: an e-mail subject to customers, both languages
            ->view('emails.cart-reminder', [
                'products' => $this->data['products'], 'code' => $this->data['code'], 'cartUrl' => $this->data['cart_url'],
                'unsubscribeUrl' => $url,
            ] + $this->identity->viewData());
    }
}
