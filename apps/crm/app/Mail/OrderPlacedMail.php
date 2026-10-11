<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a customer right after they place a storefront order.
 *
 * This is the "Shop Notification" step of the SRS's online-order workflow
 * (section 24.3) as far as an email channel can carry it - there is no
 * SMS/WhatsApp provider wired yet (see config/reminders.php).
 *
 * Queued, unlike PaymentReminderMail: this fires from an HTTP checkout
 * request, which does need to return promptly, unlike the reminder
 * scheduler which needs a synchronous send result to log against.
 */
class OrderPlacedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Order {$this->order->order_number} confirmed",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.placed',
            with: ['order' => $this->order->load('items')],
        );
    }
}
