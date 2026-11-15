<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * The automatic publishing's alert to the team (SEO Phase 5, 2026-11-14; App\Domain\Content\BlogSafety): what was
 * found, and whether publishing was paused. Internal — sent to the shop's order recipients, in plain words.
 */
final class SeoAlertMail extends Mailable
{
    public function __construct(private readonly string $shop, private readonly string $message, private readonly bool $paused) {}

    public function build(): self
    {
        $line = e($this->message);
        $then = $this->paused
            ? 'Publishing is paused. Switch it back on from the dashboard (the storefront screen, “Automatic article publishing”) once you have looked.'
            : 'Publishing continues.';

        return $this->subject("[{$this->shop}] Articles: {$this->message}")
            ->html('<p style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.6">'.e($this->shop).' — '.$line.'</p>'
                .'<p style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.6">'.e($then).'</p>');
    }
}
