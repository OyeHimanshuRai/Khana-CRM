<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Thank you for visiting" - from the restaurant to its guest, after the bill.
 *
 * Signed by the restaurant, not the platform: the guest ate at Spice Garden,
 * not at the software. The first visit says so; every visit after says
 * "welcome back", because a regular thanked as a stranger notices.
 *
 * Only ever sent to an address the guest gave the restaurant themselves -
 * see EventNotifier::billSettled.
 */
class GuestThanksMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public bool $firstVisit,
        public ?string $feedbackUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        $shop = $this->invoice->shop?->name ?? Setting::get('company_name', config('app.name'));

        return new Envelope(subject: $this->firstVisit
            ? "Thank you for your first visit to {$shop}"
            : "Thank you for coming back to {$shop}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guests.thanks',
            with: [
                'shop' => $this->invoice->shop,
                'name' => $this->invoice->customer_name ?: 'there',
            ],
        );
    }
}
