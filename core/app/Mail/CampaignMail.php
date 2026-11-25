<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Campaigns\EmailCampaigns;
use App\Domain\Notifications\MailBudget;
use App\Storefront\StorefrontIdentity;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * A campaign e-mail (2026-11-24): the team's subject and text in Arabic and English, optional products at today's price,
 * the recipient's own code when the campaign has one, and a one-click unsubscribe (link and List-Unsubscribe headers).
 * BULK mail.
 */
final class CampaignMail extends Mailable
{
    /** @param  array<string, mixed>  $data  {@see EmailCampaigns::message()} */
    public function __construct(private readonly array $data, private readonly StorefrontIdentity $identity) {}

    public function build(): self
    {
        $url = is_string($this->data['unsubscribe_url'] ?? null) ? $this->data['unsubscribe_url'] : '';
        $this->withSymfonyMessage(function (Email $message) use ($url): void {
            $headers = $message->getHeaders();
            $headers->addTextHeader(MailBudget::BULK_HEADER, MailBudget::BULK);
            $headers->addTextHeader('List-Unsubscribe', '<'.$url.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
        $ar = is_string($this->data['subject_ar'] ?? null) ? $this->data['subject_ar'] : '';
        $en = is_string($this->data['subject_en'] ?? null) ? $this->data['subject_en'] : '';

        return $this
            ->subject(trim($ar.' · '.$en, ' ·'))
            ->view('emails.campaign', [
                'bodyAr' => $this->data['body_ar'] ?? '', 'bodyEn' => $this->data['body_en'] ?? '',
                'subjectAr' => $ar, 'subjectEn' => $en,
                'products' => $this->data['products'] ?? [], 'code' => $this->data['code'] ?? null,
                'shopUrl' => $this->data['shop_url'] ?? '', 'unsubscribeUrl' => $url,
            ] + $this->identity->viewData());
    }
}
