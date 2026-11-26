<?php

namespace App\Mail;

use App\Models\Reservation;
use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// The guest's confirmation for a table booked online.
class TableBookedMail extends Mailable implements ShouldQueue
{
    use \App\Mail\Concerns\RetriesSafely, Queueable, SerializesModels;

    public function __construct(public Reservation $reservation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your table is booked - '.$this->shop()?->name);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reservations.booked',
            with: ['reservation' => $this->reservation, 'shop' => $this->shop()],
        );
    }

    private function shop(): ?Shop
    {
        return Shop::query()->withoutGlobalScopes()->find($this->reservation->shop_id);
    }
}
