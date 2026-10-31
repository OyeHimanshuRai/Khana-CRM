<?php

namespace App\Mail;

use App\Models\SubscriptionPayment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Payment received - your plan runs until ..." to the business's owners.
 *
 * Sent for every payment that extends a term: a Razorpay checkout and a
 * payment the platform recorded by hand alike, because to the owner they are
 * the same event - they paid, and need the receipt.
 */
class SubscriptionPaidMail extends PlatformMail
{
    public function __construct(
        public SubscriptionPayment $payment,
        public string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment received - '.$this->payment->reference.' - '.$this->company());
    }

    public function content(): Content
    {
        $subscription = $this->payment->subscription;

        return new Content(
            view: 'emails.subscriptions.paid',
            with: $this->brand() + [
                'business' => $subscription?->shop?->name ?? $subscription?->tenant?->name,
                'plan' => $subscription?->plan?->name,
                'invoiceUrl' => route('admin.subscription.invoice', $this->payment->id),
                'billingUrl' => route('admin.subscription.mine'),
            ],
        );
    }
}
