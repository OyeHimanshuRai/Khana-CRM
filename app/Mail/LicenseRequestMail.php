<?php

namespace App\Mail;

use App\Models\License;
use App\Models\Tenant;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the platform's own people (the Super Admins and the support address): a
 * customer needs something from them about a license - a key for a company that
 * has just registered, a key they asked for, or a key they entered that waits
 * for approval. Replying reaches the customer's owner.
 */
class LicenseRequestMail extends PlatformMail
{
    public const REGISTERED = 'registered';

    public const REQUESTED = 'requested';

    public const ENTERED = 'entered';

    public function __construct(
        public string $kind,
        public Tenant $tenant,
        public ?License $license = null,
        public ?string $ownerName = null,
        public ?string $ownerEmail = null,
        public ?string $ownerPhone = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->kind) {
            self::REGISTERED => 'New customer needs a license key: '.$this->tenant->name,
            self::REQUESTED => 'License key requested: '.$this->tenant->name,
            default => 'License waiting for approval: '.$this->tenant->name,
        };

        return new Envelope(
            replyTo: filled($this->ownerEmail) ? [new Address($this->ownerEmail, (string) $this->ownerName)] : [],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.license.request',
            with: $this->brand() + [
                'kind' => $this->kind,
                'tenant' => $this->tenant,
                'license' => $this->license,
                'ownerName' => $this->ownerName,
                'ownerEmail' => $this->ownerEmail,
                'ownerPhone' => $this->ownerPhone ?: $this->tenant->phone,
                'adminUrl' => \App\Support\Realm::platformRoute('admin.tenants.index', [
                    'license' => $this->kind === self::ENTERED ? 'pending' : ($this->license?->status ?? 'none'),
                ]),
            ],
        );
    }
}
