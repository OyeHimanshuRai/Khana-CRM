<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The "reset your password" link for somebody who signs in to the back office. */
class PasswordResetMail extends PlatformMail
{
    public function __construct(
        public User $user,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your '.$this->company().' password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.password-reset',
            with: $this->brand() + [
                'name' => $this->user->name,
                'resetUrl' => route('password.reset', ['token' => $this->token, 'email' => $this->user->email]),
                'minutes' => (int) config('auth.passwords.users.expire', 60),
            ],
        );
    }
}
