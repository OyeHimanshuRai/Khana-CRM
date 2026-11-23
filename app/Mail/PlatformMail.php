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
    use Queueable, SerializesModels, \App\Mail\Concerns\RetriesSafely;

    protected function company(): string
    {
        return (string) Setting::get('company_name', config('app.name'));
    }

    /** The email logo (or the site logo) as a path on disk; null when none was uploaded. */
    protected function logoPath(): ?string
    {
        $stored = Setting::map();
        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        foreach (['email_logo', 'site_logo'] as $key) {
            $path = $stored[$key] ?? null;

            if (filled($path) && $disk->exists($path)) {
                return $disk->path($path);
            }
        }

        return null;
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
            // The same logo as a file on this server, so it can travel inside the email itself:
            // a picture that has to be fetched from the website is hidden by most inboxes until
            // the reader allows it (and never loads if the address is wrong).
            'logoPath' => $this->logoPath(),
            'supportEmail' => Setting::get('company_email'),
            // These go to restaurants; the platform owner's own door is not theirs.
            'loginUrl' => route('login'),
        ];
    }
}
