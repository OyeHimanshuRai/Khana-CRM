<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The six-digit code that confirms an email address before sign-up. */
class SignupOtpMail extends PlatformMail
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->code.' is your '.$this->company().' verification code');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.signup-otp',
            with: $this->brand() + ['code' => $this->code, 'minutes' => \App\Support\SignupOtp::TTL_MINUTES],
        );
    }
}
