<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You asked to reset your password."
 *
 * The other half of the welcome email, and the one that matters more now that
 * restaurants open their own accounts: the owner who signed themselves up at
 * midnight has nobody to ask when they forget the password they chose.
 *
 * Carries a one-time link and no password, for the reason WelcomeUserMail
 * gives - a mailbox is searchable, forwardable and rarely encrypted at rest.
 *
 * Queued, so a slow SMTP server never holds up the response to the form. The
 * form says the same thing either way, which is what keeps it from being a
 * way to find out which addresses have accounts.
 */
class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $resetUrl,
        public int $expiresInMinutes = 60,
    ) {}

    public function envelope(): Envelope
    {
        $company = Setting::get('company_name', config('app.name'));

        return new Envelope(
            subject: "Reset your {$company} password",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.password-reset',
            with: [
                'company' => Setting::get('company_name', config('app.name')),
                'loginUrl' => route('admin.login'),
                'supportEmail' => Setting::get('company_email'),
                'logoUrl' => \App\Support\CompanySettings::fileUrl('email_logo')
                    ?? \App\Support\CompanySettings::fileUrl('site_logo'),
            ],
        );
    }
}
