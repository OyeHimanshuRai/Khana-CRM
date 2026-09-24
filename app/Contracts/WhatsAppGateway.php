<?php

namespace App\Contracts;

/**
 * What this system needs from anything that can send a WhatsApp message.
 *
 * ---------------------------------------------------------------------------
 * Deliberately not the SMS interface
 * ---------------------------------------------------------------------------
 *
 * They look the same and are not. WhatsApp will not let a business start a
 * conversation with free text: it must be a template Meta has approved, with
 * its variables filled in. Free text is allowed only inside the 24 hours
 * after the customer last wrote.
 *
 * Reusing SmsGateway here would mean every caller passing a string that
 * mostly does not send, and the failure would look like a provider being
 * unreliable rather than a rule being broken. So `send` takes a template and
 * its variables, and the plain body is a fallback for the log driver and for
 * resellers that accept one.
 */
interface WhatsAppGateway
{
    public function key(): string;

    public function label(): string;

    /**
     * Whether a message could actually be sent right now.
     *
     * False is a working state: whatever wanted to send falls back to SMS or
     * to nothing, and the restaurant carries on.
     */
    public function isConfigured(): bool;

    /**
     * Send one templated message. False when it did not go.
     *
     * @param  string  $to  the recipient, local digits
     * @param  string  $template  the key from config('whatsapp.templates')
     * @param  array<int, string>  $variables  in the order the template uses them
     * @param  string|null  $body  the same message as plain text, for drivers
     *                             that take one
     */
    public function send(string $to, string $template, array $variables = [], ?string $body = null): bool;
}
