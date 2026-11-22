<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The one-time code that proves a person owns the email address of an existing Dot.Charts account. */
class SsoClaimCodeMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $code,
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Dot.Charts confirmation code');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.sso-claim-code');
    }
}
