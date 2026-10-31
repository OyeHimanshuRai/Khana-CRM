<?php

namespace App\Support;

use App\Models\EmailLog;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Writes the outbound mail log, and remembers what is currently in flight.
 *
 * Registered as a singleton so the "in flight" pointer survives between the
 * MessageSending and MessageSent events, and so a caller that catches a send
 * failure can mark the right row without having to thread an id through its
 * own code. Laravel dispatches no event for a failed send, which is the whole
 * reason this pointer exists.
 *
 * One in flight at a time is safe here because every send in this app is
 * synchronous within the process that starts it - the campaign job included.
 */
class EmailLogger
{
    private ?EmailLog $current = null;

    /**
     * Open a log row for a message about to go out.
     *
     * @param  array<string, mixed>  $data  The mailer's data bag.
     */
    public function starting(Email $message, array $data = []): EmailLog
    {
        $to = $message->getTo();
        $first = $to[0] ?? null;

        $log = new EmailLog();

        $log->forceFill([
            // Laravel stashes the mailable class here when one was used;
            // a raw Mail::raw() send simply has none.
            'mailable' => $data['__laravel_mailable'] ?? null,

            'to_email' => $first?->getAddress() ?? 'unknown',
            'to_name' => $first?->getName() ?: null,
            // Only when a message really went to more than one address.
            'to_extra' => count($to) > 1
                ? $this->joinAddresses(array_slice($to, 1))
                : null,

            'cc' => $this->joinAddresses($message->getCc()),
            'bcc' => $this->joinAddresses($message->getBcc()),

            'from_email' => ($message->getFrom()[0] ?? null)?->getAddress(),
            'from_name' => ($message->getFrom()[0] ?? null)?->getName() ?: null,
            'reply_to' => ($message->getReplyTo()[0] ?? null)?->getAddress(),

            'subject' => Str::limit((string) $message->getSubject(), 500, ''),
            'mailer' => Str::limit((string) config('mail.default'), 40, ''),

            'status' => EmailLog::PENDING,
        ])->save();

        $this->current = $log;

        return $log;
    }

    /** The send came back without complaint. */
    public function sent(): void
    {
        $this->current?->forceFill([
            'status' => EmailLog::SENT,
            'sent_at' => now(),
        ])->save();

        $this->current = null;
    }

    /**
     * The send threw.
     *
     * Called from the places that catch a mail failure rather than let it
     * escape - see App\Support\WelcomeMailer and
     * App\Services\EmailTemplateService. Silent when nothing is in
     * flight, so a caller never has to check first.
     */
    public function failed(\Throwable $e): void
    {
        $this->current?->forceFill([
            'status' => EmailLog::FAILED,
            'error' => Str::limit($e->getMessage(), 2000, ''),
        ])->save();

        $this->current = null;
    }

    /** Convenience for the catch blocks. */
    public static function fail(\Throwable $e): void
    {
        app(self::class)->failed($e);
    }

    /**
     * @param  array<int, Address>  $addresses
     */
    private function joinAddresses(array $addresses): ?string
    {
        if (empty($addresses)) {
            return null;
        }

        return Str::limit(
            collect($addresses)
                ->map(fn (Address $address) => $address->getAddress())
                ->implode(', '),
            2000,
            '',
        );
    }
}
