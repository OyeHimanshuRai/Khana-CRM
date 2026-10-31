<?php

namespace App\Mail;

use App\Models\Subscription;
use App\Services\SubscriptionNoticeService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your trial / plan ends in two days", "...tomorrow", "...today", "...has ended".
 *
 * One mailable for the four, because they are one message at four distances
 * from the same date, and four classes would drift apart in what they say
 * about how to renew.
 */
class SubscriptionNoticeMail extends PlatformMail
{
    public function __construct(
        public Subscription $subscription,
        public string $kind,
        public string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        $what = $this->what();

        $subject = match ($this->kind) {
            SubscriptionNoticeService::ENDING_2 => "Your {$what} ends in 2 days",
            SubscriptionNoticeService::ENDING_1 => "Your {$what} ends tomorrow",
            SubscriptionNoticeService::ENDING_0 => "Your {$what} ends today",
            default => "Your {$what} has ended",
        };

        return new Envelope(subject: $subject.' - '.$this->company());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscriptions.notice',
            with: $this->brand() + [
                'kind' => $this->kind,
                'what' => $this->what(),
                'business' => $this->subscription->shop?->name ?? $this->subscription->tenant?->name,
                'plan' => $this->subscription->plan?->name,
                'endsAt' => $this->subscription->ends_at,
                'billingUrl' => route('admin.subscription.mine'),
            ],
        );
    }

    /**
     * "free trial" or "subscription".
     *
     * Asked of the dates rather than of state(): on the morning the trial
     * ends it is still a trial, and on the morning after it is no longer
     * anything, but the email is still about the trial that just ran out.
     */
    private function what(): string
    {
        $trialEnd = $this->subscription->trial_ends_at;

        return $trialEnd !== null && $this->subscription->ends_at !== null
            && $trialEnd->equalTo($this->subscription->ends_at)
            ? 'free trial'
            : 'subscription';
    }
}
