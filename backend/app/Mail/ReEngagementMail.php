<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ReEngagementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Collection $offers,
    ) {}

    public function build()
    {
        return $this
            ->subject("We miss you, {$this->user->first_name}! Exclusive offers inside")
            ->view('mails.re-engagement', [
                'user'   => $this->user,
                'offers' => $this->offers,
            ]);
    }
}
