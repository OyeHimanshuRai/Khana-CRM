<?php

namespace App\Mail;

use App\Models\Setting;
use App\Support\CompanySettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mail the platform sends about an account: sign-up and the plan's term.
 *
 * Signed by the platform (Settings > General), not by the restaurant - these
 * are from us to our customer. Queued like WelcomeUserMail, so a slow SMTP
 * server never holds up a sign-up or the morning's reminder run.
 */
abstract class PlatformMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected function company(): string
    {
        return (string) Setting::get('company_name', config('app.name'));
    }

    /**
     * What every one of these emails' layout needs.
     *
     * @return array<string, mixed>
     */
    protected function brand(): array
    {
        return [
            'company' => $this->company(),
            'logoUrl' => CompanySettings::fileUrl('email_logo') ?? CompanySettings::fileUrl('site_logo'),
            'supportEmail' => Setting::get('company_email'),
            // These go to restaurants; the platform owner's own door is not theirs.
            'loginUrl' => route('login'),
        ];
    }
}
