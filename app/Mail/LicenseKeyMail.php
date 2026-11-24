<?php

namespace App\Mail;

use App\Models\License;
use App\Models\Tenant;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To a customer: the license key the platform has issued for their company.
 *
 * Sent at once, with the plain key in hand, by the Super Admin from the
 * company's License panel. The key is never stored - this email is the only
 * copy that leaves the server - and it works exactly once.
 */
class LicenseKeyMail extends PlatformMail
{
    public function __construct(public Tenant $tenant, public License $license, public string $key) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.$this->company().' license key');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.license.key',
            with: $this->brand() + [
                'tenant' => $this->tenant,
                'license' => $this->license,
                'key' => $this->key,
                'planName' => $this->license->plan?->name,
                'signInUrl' => url('/login'),
            ],
        );
    }
}
