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
 */
final class ReviewRequestMail extends Mailable
{
    /** @param  list<string>  $products */
    public function __construct(
        private readonly string $url,
        private readonly array $products,
        private readonly StorefrontIdentity $identity,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('كيف وجدت طلبك؟ · How was your order?')
            ->view('emails.review-request', ['url' => $this->url, 'products' => array_slice($this->products, 0, 5)] + $this->identity->viewData());
    }
}
