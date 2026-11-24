<?php

namespace App\Mail;

use App\Models\DemoRequest;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the platform's own people (the Super Admins and the support address): somebody wrote
 * to support from the website. Replying to it reaches the person who asked.
 */
class SupportRequestMail extends PlatformMail
{
    public function __construct(public DemoRequest $lead) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->lead->email ? [new Address($this->lead->email, (string) $this->lead->name)] : [],
            subject: 'New support request: '.($this->lead->business_name ?: $this->lead->name),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.support.request',
            with: $this->brand() + [
                'lead' => $this->lead,
                'adminUrl' => \App\Support\Realm::platformRoute('admin.demo-requests.show', $this->lead->id),
            ],
        );
    }
}
