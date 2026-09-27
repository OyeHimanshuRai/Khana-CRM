<?php

namespace Database\Seeders;

use App\Models\CashRegister;
use App\Models\Payment;
use App\Models\PaymentReminder;
use App\Services\CashRegisterService;
use App\Services\ReminderService;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The till, and chasing money in.
 *
 * The registers are opened and closed through CashRegisterService, so the
 * expected cash on each one is computed from the day's actual cash payments
 * rather than typed in - which is the only way the variance column means
 * anything. A counted figure is then declared slightly off on some days,
 * because a till that balances to the paisa every single day is the one
 * thing a shopkeeper will not believe.
 *
 * Reminders are scheduled by ReminderService against the invoices that are
 * genuinely overdue. They are not dispatched: sending would put real mail in
 * a real queue against addresses that do not exist. Their outcomes are
 * stamped instead, which is the one place here that writes a status a
 * service would normally own - and it is stamped rather than sent precisely
 * so that no mail leaves the building.
 */
class DemoFinanceSeeder extends Seeder
{
    use SeedsDemoData;

    public function __construct(
        private readonly CashRegisterService $registers,
        private readonly ReminderService $reminders,
    ) {}

    public function run(): void
    {
        $this->seedRandom(6);

        $this->cashRegisters();
        $this->paymentReminders();
    }

    /**
     * Twenty trading days of till sessions.
     *
     * Worked backwards from today so the most recent day is left open, which
     * is what a register screen looks like at four in the afternoon.
     */
    private function cashRegisters(): void
    {
        if ($this->alreadySeeded('Cash registers', CashRegister::query()->count())) {
            return;
        }

        $shop = CurrentShop::get();
        $opened = 0;
        $closed = 0;
        $approved = 0;

        for ($back = self::PER_MODULE - 1; $back >= 0; $back--) {
            $date = Carbon::today()->subDays($back);

            try {
                $register = $this->registers->open($shop, (float) $this->pick([2000, 3000, 5000]), $date);
                $opened++;
            } catch (RuntimeException) {
                // Already opened for that date on a previous run.
                continue;
            }

            // Today's till is still open - there is a counter to run.
            if ($back === 0) {
                continue;
            }

            /*
             | The counted figure is the expected one nudged by a few rupees
             | either way, and now and then left exact. That is what gives the
             | variance column something to show and the approval step a
             | reason to exist.
             */
            $expected = $this->registers->expectedCash($register);

            $counted = match (true) {
                $this->chance(55) => $expected,
                $this->chance(50) => $expected - $this->money(5, 240),
                default => $expected + $this->money(5, 180),
            };

            $this->registers->close($register, round(max(0, $counted), 2), $this->pick([
                'Counted by the evening cashier.',
                'Short by a few rupees, change given without a note.',
                null,
            ]));
            $closed++;

            // The last few days are left awaiting sign-off.
            if ($back > 3) {
                $this->registers->approve($register->refresh(), 'Checked against the day book.');
                $approved++;
            }
        }

        $this->say(sprintf('%d cash registers (%d closed, %d approved).', $opened, $closed, $approved));
    }

    /**
     * Reminders against the invoices that are actually overdue.
     *
     * Scheduled by the service, so which invoice earns which trigger follows
     * config/reminders.php rather than a guess made here.
     */
    private function paymentReminders(): void
    {
        if ($this->alreadySeeded('Payment reminders', PaymentReminder::query()->count(), 5)) {
            return;
        }

        $shop = CurrentShop::get();
        $result = $this->reminders->schedule($shop);

        if (($result['scheduled'] ?? 0) === 0) {
            $this->say(sprintf(
                'Payment reminders: none due (%d invoices considered).',
                $result['considered'] ?? 0,
            ));

            return;
        }

        /*
         | Outcomes are stamped, not dispatched. ReminderService::send() would
         | hand real mail to the queue for addresses invented by
         | DemoPartySeeder; nothing about a demo is worth that. The statuses
         | below are the ones the list screen filters on, including the
         | failures that raise the dashboard's alert.
         */
        $pending = PaymentReminder::query()
            ->where('status', PaymentReminder::PENDING)
            ->orderBy('id')
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($pending as $index => $reminder) {
            if ($index % 7 === 6) {
                $reminder->forceFill([
                    'status' => PaymentReminder::FAILED,
                    'attempts' => $this->between(1, 3),
                    'last_error' => 'Mailbox unavailable (550).',
                ])->save();
                $failed++;

                continue;
            }

            // Every fourth is left queued, so the module has work in hand.
            if ($index % 4 === 3) {
                continue;
            }

            $reminder->forceFill([
                'status' => PaymentReminder::SENT,
                'sent_at' => $this->recentDate(20),
                'attempts' => 1,
            ])->save();
            $sent++;
        }

        $this->say(sprintf(
            '%d payment reminders (%d sent, %d failed, %d queued).',
            $pending->count(),
            $sent,
            $failed,
            $pending->count() - $sent - $failed,
        ));
    }
}
