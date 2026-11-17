<?php

declare(strict_types=1);

namespace App\Mail;

use App\Storefront\StorefrontIdentity;
use Illuminate\Mail\Mailable;

/**
 * "How is your order? Tell other shoppers" (SEO Phase 5 item 1, 2026-11-14): sent by `reviews:request` a few days after
 * an order is delivered, while REVIEW_REQUESTS_ENABLED is on. Both languages in one message (Arabic first), the shop's
 * own layout (StorefrontIdentity), the products of the order named, one button to the review page. Sent synchronously
 * from the storefront's own mailbox (StorefrontMailer), as every customer mail is.
 * Since 2026-11-17: the shop's Google review buttons, one per branch with a link (Reviews::googleLinks), to everyone —
 * never gated on what they think of the order, and no incentive of any kind.
 */
final class ReviewRequestMail extends Mailable
{
    /**
     * @param  list<string>  $products
     * @param  list<array{key: ?string, name: array{en: string, ar: string}, url: string}>  $google
     */
    public function __construct(
        private readonly string $url,
        private readonly array $products,
        private readonly StorefrontIdentity $identity,
        private readonly array $google = [],
    ) {}

    public function build(): self
    {
        return $this
            ->subject('كيف وجدت طلبك؟ · How was your order?')
            ->view('emails.review-request', ['url' => $this->url, 'products' => array_slice($this->products, 0, 5), 'google' => $this->google] + $this->identity->viewData());
    }
}
