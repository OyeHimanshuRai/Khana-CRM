<?php

namespace App\Mail;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the owner who has just signed up: you are in, here is how to get back.
 *
 * Carries the sign-in address and the link, never the password - the owner
 * chose it a moment ago, and a mailbox is searchable, forwardable and rarely
 * encrypted at rest. The same rule as WelcomeUserMail.
 */
class TrialWelcomeMail extends PlatformMail
{
    public function __construct(
        public User $user,
        public Subscription $subscription,
    ) {}

    public function envelope(): Envelope
    {
        // The sender is RestroOne whatever the company name setting says; the
        // address is the configured (Gmail SMTP) one, so the server accepts it.
        return new Envelope(
            from: new Address((string) config('mail.from.address'), 'RestroOne'),
            subject: 'Welcome to RestroOne – Registration Successful',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscriptions.welcome',
            with: $this->brand() + [
                'business' => $this->user->tenant?->name,
                'plan' => $this->subscription->plan?->name,
                'trialEnds' => $this->subscription->onTrial() ? $this->subscription->ends_at : null,
                'billingUrl' => route('admin.subscription.mine'),
                // The account opens once this is followed; null for one that needs no confirming.
                'verifyUrl' => $this->user->needsEmailVerification() ? $this->user->emailVerificationUrl() : null,
            ],
        );
    }
}
