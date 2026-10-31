<?php

namespace App\Mail;

use App\Models\Customer;
use App\Models\Shop;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The "reset your password" link for a customer of one shop's storefront. */
class CustomerPasswordResetMail extends PlatformMail
{
    public function __construct(
        public Customer $customer,
        public Shop $shop,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password for '.($this->shop->storefront_name ?? $this->shop->name));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.password-reset',
            // The shop's own name and sign-in, not the platform's.
            with: [
                'company' => $this->shop->storefront_name ?? $this->shop->name,
                'loginUrl' => route('shop.login', $this->shop),
                'name' => $this->customer->name,
                'resetUrl' => route('shop.password.reset', ['shop' => $this->shop, 'token' => $this->token, 'email' => $this->customer->email]),
                'minutes' => (int) config('auth.passwords.customers.expire', 60),
            ] + $this->brand(),
        );
    }
}
