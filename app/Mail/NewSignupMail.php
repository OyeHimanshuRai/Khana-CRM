<?php

namespace App\Mail;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the platform's own staff: somebody has just signed up.
 *
 * The mobile number is in it on purpose. The first week of a trial is when a
 * phone call turns a trial into a customer, and the person who would make
 * that call should not have to open the admin to find the number.
 */
class NewSignupMail extends PlatformMail
{
    public function __construct(
        public User $owner,
        public Subscription $subscription,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New sign-up: '.($this->owner->tenant?->name ?? $this->owner->name));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscriptions.new-signup',
            with: $this->brand() + [
                'business' => $this->owner->tenant?->name,
                'plan' => $this->subscription->plan?->name,
                'period' => $this->subscription->billing_period,
                'trialEnds' => $this->subscription->ends_at,
                'tenantUrl' => $this->owner->tenant_id ? route('admin.tenants.show', $this->owner->tenant_id) : null,
            ],
        );
    }
}
