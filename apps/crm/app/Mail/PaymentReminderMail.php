<?php

namespace App\Mail;

use App\Models\PaymentReminder;
use App\Support\CompanySettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A payment reminder to a customer.
 *
 * Deliberately NOT queued, unlike the welcome mail: ReminderService is
 * already running from the scheduler, off the request path, and it needs to
 * know whether each send succeeded so it can record the attempt against the
 * right reminder. Handing it to a queue would put that answer somewhere the
 * log could not see it.
 *
 * The subject was composed and stored when the reminder was scheduled, so
 * what goes out matches what the shop was shown it would send.
 */
class PaymentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public PaymentReminder $reminder) {}

    public function envelope(): Envelope
    {
        $shop = $this->reminder->shop;

        return new Envelope(
            subject: $this->reminder->subject ?: 'Payment reminder',
            // Replies should reach the shop that is owed the money, not a
            // group inbox for the whole business.
            replyTo: filled($shop?->email) ? [$shop->email] : [],
        );
    }

    public function content(): Content
    {
        $invoice = $this->reminder->invoice;
        $shop = $this->reminder->shop;

        $daysOverdue = $invoice?->daysOverdue();

        return new Content(
            view: 'emails.reminders.payment',
            with: [
                'reminder' => $this->reminder,
                'customer' => $this->reminder->customer,
                'invoice' => $invoice,
                'shop' => $shop,
                'amount' => (float) $this->reminder->amount_due,
                'dueDate' => $this->reminder->due_date,
                // Negative while still in date, which the template reads to
                // decide whether this is a heads-up or a chase.
                'daysOverdue' => $daysOverdue,
                'logoUrl' => $shop?->logoUrl()
                    ?? CompanySettings::fileUrl('email_logo')
                    ?? CompanySettings::fileUrl('site_logo'),
            ],
        );
    }
}
