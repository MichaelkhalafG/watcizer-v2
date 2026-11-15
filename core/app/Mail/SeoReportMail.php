<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The weekly SEO report of a shop (Michael, 2026-11-14: e-mailed as well as written to a file) — the Markdown as is. */
final class SeoReportMail extends Mailable
{
    public function __construct(private readonly string $shop, private readonly string $report) {}

    public function build(): self
    {
        return $this->subject("[{$this->shop}] Weekly SEO report")
            ->html('<pre style="font-family:Consolas,Menlo,monospace;font-size:13px;line-height:1.5;white-space:pre-wrap">'.e($this->report).'</pre>');
    }
}
