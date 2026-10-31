<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The email-confirmation link again, when the welcome email's was lost or expired. */
class VerifyEmailMail extends PlatformMail
{
    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your email for '.$this->company());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.verify-email',
            with: $this->brand() + ['verifyUrl' => $this->user->emailVerificationUrl()],
        );
    }
}
