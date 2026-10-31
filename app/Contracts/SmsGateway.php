<?php

namespace App\Contracts;

/**
 * What this system needs from anything that can reach a phone (§3.8, §14).
 *
 * Four methods. A bulk-SMS provider offers scheduling, campaigns, delivery
 * receipts, unicode templates and a dashboard; none of that belongs here,
 * because a restaurant that changes provider should have to rewrite one
 * class and nothing else.
 *
 * `send` returns a bool rather than throwing, and the reason matters: a
 * failed text is a normal Tuesday. A provider with no balance, a number that
 * has opted out, a carrier dropping traffic - all of them have to end with
 * the guest being told "we could not send that, try again or ask a member of
 * staff", not with a five-hundred on a phone in a restaurant.
 */
interface SmsGateway
{
    /** The key this gateway is configured under. */
    public function key(): string;

    /** What to call it on a settings screen. */
    public function label(): string;

    /**
     * Whether this gateway could actually send something right now.
     *
     * False is a working state: the OTP step refuses to switch on and says
     * why, rather than silently accepting every code or silently sending
     * none.
     */
    public function isConfigured(): bool;

    /**
     * Send one message. False when it did not go.
     *
     * Implementations must not throw for an ordinary failure - see the class
     * comment. Anything worth a person's attention goes through report().
     */
    public function send(string $to, string $message): bool;
}
