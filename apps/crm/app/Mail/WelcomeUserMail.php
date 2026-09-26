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
 * Sent when an administrator creates an account for someone.
 *
 * Deliberately carries no password. The admin types one into the create
 * form, but mailboxes are searchable, forwardable and rarely encrypted at
 * rest - so the recipient gets a one-time link to choose their own instead.
 *
 * Queued, so a slow or unreachable SMTP server never holds up the response
 * to the admin who pressed Create.
 */
class WelcomeUserMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  string|null  $setPasswordUrl  Signed link, or null when the
     *                                       token could not be issued.
     */
    public function __construct(
        public User $user,
        public ?string $setPasswordUrl = null,
        public int $expiresInMinutes = 60,
    ) {}

    public function envelope(): Envelope
    {
        $company = Setting::get('company_name', config('app.name'));

        return new Envelope(
            subject: "Your {$company} account is ready",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.welcome',
            with: [
                'company' => Setting::get('company_name', config('app.name')),
                'loginUrl' => route('admin.login'),
                'supportEmail' => Setting::get('company_email'),
                'logoUrl' => \App\Support\CompanySettings::fileUrl('email_logo')
                    ?? \App\Support\CompanySettings::fileUrl('site_logo'),
                'roles' => $this->user->roles->pluck('name'),
            ],
        );
    }
}
