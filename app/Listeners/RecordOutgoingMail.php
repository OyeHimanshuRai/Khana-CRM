<?php

namespace App\Listeners;

use App\Support\EmailLogger;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Turns every outbound message into an email_logs row.
 *
 * Registered explicitly in AppServiceProvider rather than discovered:
 * bootstrap/app.php does not call withEvents(), so nothing in app/Listeners
 * is auto-registered, and being explicit also makes the wiring greppable.
 *
 * Both handlers swallow their own failures. A logging table falling over must
 * never stop an email going out - that would make the observability worse
 * than having none.
 */
class RecordOutgoingMail
{
    public function __construct(private readonly EmailLogger $logger)
    {
    }

    public function sending(MessageSending $event): void
    {
        try {
            $this->logger->starting($event->message, $event->data);
        } catch (\Throwable $e) {
            Log::warning('Could not open an email log row', ['error' => $e->getMessage()]);
        }
    }

    public function sent(MessageSent $event): void
    {
        try {
            $this->logger->sent();
        } catch (\Throwable $e) {
            Log::warning('Could not close an email log row', ['error' => $e->getMessage()]);
        }
    }
}
