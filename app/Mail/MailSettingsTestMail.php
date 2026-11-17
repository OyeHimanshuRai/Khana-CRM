<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The message behind "Send test email" on Settings > General > Mail.
 *
 * Sent rather than queued: whoever pressed the button is waiting to learn
 * whether the saved settings work, and a queued test only proves the queue.
 * It says which mailer and host sent it (never the password) so a test that
 * arrives can be matched to the settings that produced it.
 */
class MailSettingsTestMail extends Mailable
{
    public function __construct(
        public string $driver,
        public ?string $host,
        public string $sentBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Test] Mail settings check');
    }

    public function content(): Content
    {
        $e = fn (?string $v) => e($v ?: '-');

        return new Content(htmlString: '<p>This is a test email from <strong>'.$e(config('app.name')).'</strong>.</p>'
            .'<p>If you can read this, the saved mail settings work.</p>'
            .'<p style="color:#666;font-size:13px">Mailer: '.$e($this->driver)
            .' &middot; Host: '.$e($this->host)
            .' &middot; Sent by: '.$e($this->sentBy)
            .' &middot; '.e(now()->toDayDateTimeString()).'</p>');
    }
}
