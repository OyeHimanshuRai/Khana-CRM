<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\Setting;
use App\Models\Shop;
use App\Mail\PaymentReminderMail;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use App\Services\Sms\SmsManager;
use App\Services\Whatsapp\WhatsAppManager;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The reminder engine.
 *
 * Two halves, deliberately separate:
 *
 *   schedule()  works out what should be said and writes it down
 *   dispatch()  says it
 *
 * Splitting them means a scheduling run cannot be delayed by a slow mail
 * server, a mail outage loses nothing, and the shop can look at what is
 * about to be sent before it goes.
 *
 * Both are idempotent. Scheduling relies on a unique index rather than on a
 * "have I done this" query, so running it twice in the same minute - or
 * running it after a crash halfway through - cannot double-send. That
 * matters more than it sounds: a shop whose reminders arrive twice gets
 * marked as spam and then none of them arrive.
 */
class ReminderService
{
    /**
     * Write down every reminder that is now due to be prepared.
     *
     * Runs over every shop, so it is safe from the scheduler where there is
     * no authenticated user and no shop context.
     *
     * @return array{scheduled: int, considered: int}
     */
    public function schedule(?Shop $only = null): array
    {
        if (! config('reminders.enabled', true)) {
            return ['scheduled' => 0, 'considered' => 0];
        }

        $scheduled = 0;
        $considered = 0;

        $shops = $only ? collect([$only]) : Shop::query()->active()->get();
        $triggers = $this->activeTriggers();
        $channels = $this->activeChannels();

        if ($triggers->isEmpty() || $channels->isEmpty()) {
            return ['scheduled' => 0, 'considered' => 0];
        }

        foreach ($shops as $shop) {
            /*
             | allShops() because this runs from cron with no actor and no
             | shop context - the tenant scope would let everything through
             | anyway, but saying so makes the intent explicit.
             */
            $invoices = Invoice::allShops()
                ->with('customer')
                ->where('shop_id', $shop->id)
                ->outstanding()
                ->whereNotNull('due_date')
                ->whereNotNull('customer_id')
                ->cursor();

            foreach ($invoices as $invoice) {
                $considered++;

                foreach ($triggers as $key => $trigger) {
                    if (! $this->isDueForTrigger($invoice, $trigger)) {
                        continue;
                    }

                    foreach ($channels as $channel => $_) {
                        if ($this->write($invoice, $shop, $key, $trigger, $channel)) {
                            $scheduled++;
                        }
                    }
                }
            }
        }

        if ($scheduled > 0) {
            ActivityLog::record(
                'reminder.scheduled',
                "Scheduled {$scheduled} payment reminder(s) across {$considered} outstanding invoice(s)",
            );
        }

        return ['scheduled' => $scheduled, 'considered' => $considered];
    }

    /**
     * Whether an invoice has reached a trigger's moment.
     *
     * The comparison is "today is at or past the trigger date", not "today
     * equals it", so a scheduler that did not run for two days catches up
     * instead of silently skipping everyone.
     *
     * @param  array<string, mixed>  $trigger
     */
    private function isDueForTrigger(Invoice $invoice, array $trigger): bool
    {
        $offset = (int) ($trigger['offset_days'] ?? 0);
        $fires = $invoice->due_date->copy()->addDays($offset);

        return ! today()->isBefore($fires);
    }

    /**
     * Write one reminder row, or leave the existing one alone.
     *
     * Returns false when the unique index says it has already been raised -
     * which is the normal case on every run after the first, and not an
     * error.
     *
     * @param  array<string, mixed>  $trigger
     */
    private function write(
        Invoice $invoice,
        Shop $shop,
        string $key,
        array $trigger,
        string $channel,
    ): bool {
        $customer = $invoice->customer;
        $recipient = $this->recipientFor($customer, $channel);

        $subject = strtr($trigger['subject'] ?? 'Payment reminder', [
            ':shop' => $shop->name,
            ':amount' => '₹'.number_format((float) $invoice->due_total, 2),
            ':due_date' => $invoice->due_date->format('d M Y'),
            ':number' => $invoice->number,
            ':customer' => $customer->name,
        ]);

        try {
            PaymentReminder::query()->create([
                'shop_id' => $shop->id,
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
                'trigger' => $key,
                'channel' => $channel,
                // No address is not a failure to retry, it is a fact about
                // the customer - so it is skipped at scheduling time and
                // shows in the log as such.
                'status' => $recipient ? PaymentReminder::PENDING : PaymentReminder::SKIPPED,
                'skip_reason' => $recipient ? null : 'No '.$channel.' address on the customer record',
                'scheduled_for' => $this->nextSendableMoment(),
                'recipient' => $recipient,
                'subject' => $subject,
                'amount_due' => (float) $invoice->due_total,
                'due_date' => $invoice->due_date,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Already raised for this invoice, trigger and channel. This is
            // what makes the scheduler safe to run as often as anyone likes.
            return false;
        }
    }

    /**
     * Send what is due.
     *
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function dispatch(int $limit = null): array
    {
        if (! config('reminders.enabled', true)) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $limit ??= (int) config('reminders.per_run', 200);

        $reminders = PaymentReminder::allShops()
            ->with(['customer', 'invoice', 'shop'])
            ->due()
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get();

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($reminders as $reminder) {
            $outcome = $this->send($reminder);

            match ($outcome) {
                'sent' => $sent++,
                'skipped' => $skipped++,
                default => $failed++,
            };
        }

        if ($sent + $failed > 0) {
            ActivityLog::record(
                'reminder.dispatched',
                "Sent {$sent} payment reminder(s), {$skipped} no longer needed, {$failed} failed",
            );
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Send one, or work out why it should not be.
     *
     * @return 'sent'|'skipped'|'failed'
     */
    public function send(PaymentReminder $reminder): string
    {
        $invoice = $reminder->invoice;

        /*
         | The gap between scheduling and sending is where most of the value
         | is: a customer who paid yesterday must not be chased today. Every
         | reason to stay quiet is checked here, at the last moment.
         */
        if ($invoice === null) {
            $reminder->markSkipped('The invoice no longer exists');

            return 'skipped';
        }

        if ($invoice->isCancelled()) {
            $reminder->markSkipped('The invoice was cancelled');

            return 'skipped';
        }

        if ((float) $invoice->due_total <= 0.004) {
            $reminder->markSkipped('Already paid');

            return 'skipped';
        }

        if (blank($reminder->recipient)) {
            $reminder->markSkipped('No address to send to');

            return 'skipped';
        }

        try {
            $sent = match ($reminder->channel) {
                'email' => $this->byEmail($reminder),
                'sms' => $this->bySms($reminder),
                'whatsapp' => $this->byWhatsApp($reminder),
                default => null,
            };

            /*
             | Null means the channel has no provider behind it at all - a
             | driver nobody configured, or a channel added to the config and
             | not to this method. An honest skip beats a row that claims to
             | have sent something nobody sent.
             */
            if ($sent === null) {
                $reminder->markSkipped($reminder->channelLabel().' is not connected to a provider yet');

                return 'skipped';
            }

            if ($sent === false) {
                $reminder->markFailed($reminder->channelLabel().' refused the message');

                return 'failed';
            }

            $reminder->markSent();

            return 'sent';
        } catch (Throwable $e) {
            report($e);
            $reminder->markFailed($e->getMessage());

            return 'failed';
        }
    }

    private function byEmail(PaymentReminder $reminder): bool
    {
        Mail::to($reminder->recipient)->send(new PaymentReminderMail($reminder));

        return true;
    }

    private function bySms(PaymentReminder $reminder): ?bool
    {
        $sms = app(SmsManager::class);

        if (! $sms->isLive()) {
            return null;
        }

        return $sms->gateway()->send($reminder->recipient, $this->plainBody($reminder));
    }

    /**
     * A reminder over WhatsApp.
     *
     * Sent as a template, because WhatsApp will not let a business open a
     * conversation with free text - see config/whatsapp.php. The plain body
     * goes along for the drivers that take one.
     */
    private function byWhatsApp(PaymentReminder $reminder): ?bool
    {
        $whatsapp = app(WhatsAppManager::class);

        if (! $whatsapp->isLive()) {
            return null;
        }

        return $whatsapp->send(
            $reminder->recipient,
            'reminder',
            [
                (string) ($reminder->customer?->name ?? 'there'),
                number_format((float) $reminder->amount, 2),
                $reminder->invoice?->number ?? '',
            ],
            $this->plainBody($reminder),
        );
    }

    /**
     * The reminder in one line, for channels with no layout.
     *
     * Deliberately short. An SMS is charged per 160 characters and read on a
     * lock screen, so it says who, how much and what for, and nothing else.
     */
    private function plainBody(PaymentReminder $reminder): string
    {
        return sprintf(
            '%s: %s is due against %s. Please settle at your convenience.',
            Setting::get('company_name', config('app.name')),
            number_format((float) $reminder->amount, 2),
            $reminder->invoice?->number ?? 'your account',
        );
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * The next moment inside the shop's quiet hours.
     *
     * Nobody wants a demand for money at half past five in the morning, and
     * a shop that sends them stops being read.
     */
    private function nextSendableMoment(): CarbonInterface
    {
        $now = now();

        $from = Carbon::parse(config('reminders.send_between.from', '09:00'));
        $to = Carbon::parse(config('reminders.send_between.to', '19:00'));

        $openAt = $now->copy()->setTime($from->hour, $from->minute);
        $closeAt = $now->copy()->setTime($to->hour, $to->minute);

        if ($now->lt($openAt)) {
            return $openAt;
        }

        if ($now->gt($closeAt)) {
            return $openAt->addDay();
        }

        return $now;
    }

    /**
     * Where a reminder can actually reach this customer on this channel.
     */
    private function recipientFor($customer, string $channel): ?string
    {
        return match ($channel) {
            'email' => filled($customer->email) ? $customer->email : null,
            'sms', 'whatsapp' => filled($customer->mobile) ? $customer->mobile : null,
            default => null,
        };
    }

    /**
     * @return \Illuminate\Support\Collection<string, array<string, mixed>>
     */
    private function activeTriggers()
    {
        return collect(config('reminders.triggers', []))
            ->filter(fn (array $trigger) => (bool) ($trigger['enabled'] ?? false));
    }

    /**
     * @return \Illuminate\Support\Collection<string, array<string, mixed>>
     */
    private function activeChannels()
    {
        return collect(config('reminders.channels', []))
            ->filter(fn (array $channel) => (bool) ($channel['enabled'] ?? false));
    }
}
