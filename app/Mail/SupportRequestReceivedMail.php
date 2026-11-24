<?php

namespace App\Mail;

use App\Models\DemoRequest;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the person who wrote to support: we have it, here is a copy, we will reply by email. */
class SupportRequestReceivedMail extends PlatformMail
{
    public function __construct(public DemoRequest $lead) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'We received your message - '.$this->company().' support');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.support.received',
            with: $this->brand() + ['lead' => $this->lead],
        );
    }
}
