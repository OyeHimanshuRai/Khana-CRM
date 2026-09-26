<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One copy of a template, sent to an address the operator names.
 *
 * Sent rather than queued: the operator is standing at the screen waiting to
 * see whether it arrives, and a queued test that lands two minutes later
 * tells them nothing about whether the template works.
 *
 * The body arrives already rendered, by EmailTemplateService, so the preview
 * on screen and the message in the inbox are the same string - a test built
 * from a second rendering path is a test of something nobody will receive.
 *
 * The subject is prefixed so a test copy is never mistaken for the real
 * thing in a shared mailbox.
 */
class EmailTemplateTestMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public EmailTemplate $template,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Test] '.($this->template->subject ?: $this->template->name),
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body);
    }
}
