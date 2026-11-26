<?php

namespace App\Services;

use App\Mail\TableBookedMail;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Services\Payments\GatewayManager;
use App\Support\OnlineBooking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

// Guests booking a table from the storefront: live slots, a held table while the deposit is paid, confirmation, refunds.
class OnlineBookingService
{
    // How long a hold outlives its timer, so a payment finishing at the last second still lands.
    private const GRACE_MINUTES = 2;

    private const MAX_OPEN_HOLDS = 3;

    public const EXPIRED_NOTE = 'Deposit not paid in time.';

    public function __construct(
        private readonly PaymentIntentService $payments,
        private readonly GatewayManager $gateways,
        private readonly AlertService $alerts,
    ) {}

    /* ------------------------------------------------------------- slots */

    /**
     * Every start time on a day, and whether a table fits the party then.
     *
     * @return array<int, array{time: string, available: bool, reason: ?string}>
     */
    public function slots(Shop $shop, string $date, int $partySize): array
    {
        $settings = $this->assertBookable($shop, $date, $partySize);
        $day = Carbon::parse($date);
        $now = OnlineBooking::now($shop);
        $earliest = $now->copy()->addMinutes((int) $settings['lead_minutes']);
        $tables = $this->tablesFor($shop, $partySize);
        $booked = $this->liveBookings($shop, $day->copy()->subDay(), $day->copy()->addDays(2));

        $slots = [];
        foreach ($this->times($settings) as $time) {
            $at = $day->copy()->setTimeFromTimeString($time);
            $ends = $at->copy()->addMinutes((int) $settings['duration_minutes']);

            if ($at->lt($earliest)) {
                $slots[] = ['time' => $time, 'available' => false, 'reason' => 'past'];

                continue;
            }

            $free = $tables->contains(fn (RestaurantTable $table) => ! $this->clashes($booked, $table->id, $at, $ends));
            $slots[] = ['time' => $time, 'available' => $free, 'reason' => $free ? null : 'full'];
        }

        return $slots;
    }

    /* -------------------------------------------------------------- hold */

    /**
     * Take a table for the guest: confirmed at once with no deposit, otherwise held while they pay.
     *
     * @param  array{date: string, time: string, party_size: int, name: string, mobile: string, notes?: ?string}  $data
     * @return array{0: Reservation, 1: ?PaymentIntent}
     *
     * @throws RuntimeException with a message safe to show the guest
     */
    public function hold(Shop $shop, Customer $customer, array $data): array
    {
        $partySize = (int) $data['party_size'];
        $settings = $this->assertBookable($shop, $data['date'], $partySize);
        $deposit = OnlineBooking::depositFor($settings, $partySize);

        if ($deposit > 0 && ! $this->gateways->isLive()) {
            throw new RuntimeException('Online payment is not available right now. Please call the restaurant to book.');
        }

        $slot = collect($this->slots($shop, $data['date'], $partySize))->firstWhere('time', $data['time']);
        if ($slot === null) {
            throw new RuntimeException('Please choose one of the listed times.');
        }
        if (! $slot['available']) {
            throw new RuntimeException($slot['reason'] === 'past'
                ? 'That time is too soon to book online. Please choose a later time.'
                : 'Sorry, that time was just taken. Please choose another.');
        }

        $open = Reservation::allShops()
            ->where('customer_id', $customer->id)
            ->where('status', Reservation::REQUESTED)
            ->where('hold_expires_at', '>', now())
            ->count();
        if ($open >= self::MAX_OPEN_HOLDS) {
            throw new RuntimeException('You have bookings waiting for payment. Please pay for or cancel them first.');
        }

        $at = Carbon::parse($data['date'])->setTimeFromTimeString($data['time']);
        $minutes = (int) $settings['duration_minutes'];

        $reservation = DB::transaction(function () use ($shop, $customer, $data, $partySize, $deposit, $settings, $at, $minutes) {
            $table = $this->freeTable($shop, $partySize, $at, $minutes);
            if ($table === null) {
                throw new RuntimeException('Sorry, that time was just taken. Please choose another.');
            }

            $reservation = new Reservation([
                'shop_id' => $shop->id,
                'restaurant_table_id' => $table->id,
                'customer_id' => $customer->id,
                'guest_name' => $data['name'],
                'guest_mobile' => $data['mobile'],
                'guest_email' => $customer->email,
                'party_size' => $partySize,
                'reserved_for' => $at,
                'duration_minutes' => $minutes,
                'status' => $deposit > 0 ? Reservation::REQUESTED : Reservation::CONFIRMED,
                'source' => 'online',
                'notes' => $data['notes'] ?? null,
            ]);
            $reservation->forceFill([
                'deposit_amount' => $deposit,
                'hold_expires_at' => $deposit > 0 ? now()->addMinutes((int) $settings['hold_minutes']) : null,
                'confirmed_at' => $deposit > 0 ? null : now(),
            ])->save();

            return $reservation;
        });

        if ($deposit <= 0) {
            $this->announce($reservation);

            return [$reservation, null];
        }

        try {
            $intent = $this->payments->open($reservation, $deposit, $shop->id);
        } catch (Throwable $e) {
            // No payment window, no hold: the table goes straight back.
            $reservation->delete();
            report($e);

            throw new RuntimeException('The payment could not be started. Please try again in a moment.', 0, $e);
        }

        return [$reservation, $intent];
    }

    // "Pay now" again after closing the window or a failed attempt, while the hold lasts.
    public function retry(Reservation $reservation): PaymentIntent
    {
        if (! $reservation->awaitingPayment()) {
            throw new RuntimeException($reservation->status === Reservation::CONFIRMED
                ? 'This booking is already confirmed.'
                : 'The time to pay for this booking has run out. Please book again.');
        }

        return $this->payments->open($reservation, (float) $reservation->deposit_amount, $reservation->shop_id);
    }

    /* -------------------------------------------------------- paid / held */

    // Called by PaymentIntentService once a deposit is captured (browser or webhook), inside its transaction.
    public function paid(Reservation $reservation, PaymentIntent $intent): void
    {
        $locked = Reservation::allShops()->whereKey($reservation->id)->lockForUpdate()->first();
        if ($locked === null) {
            $this->refundLater($intent, 'Booking no longer exists');

            return;
        }

        $expired = $locked->status === Reservation::CANCELLED && $locked->outcome_note === self::EXPIRED_NOTE;

        if ($locked->status !== Reservation::REQUESTED && ! $expired) {
            // Paid twice, or after the guest cancelled: the money goes back.
            $this->refundLater($intent, 'Deposit received for a '.$locked->status.' booking');

            return;
        }

        if (! $this->keepTable($locked)) {
            $locked->forceFill([
                'status' => Reservation::CANCELLED,
                'outcome_note' => 'The table was taken before the deposit arrived. Full refund issued.',
                'closed_at' => now(),
                'hold_expires_at' => null,
            ])->save();
            $this->refundLater($intent, 'Table taken before the deposit arrived');

            return;
        }

        $locked->forceFill([
            'status' => Reservation::CONFIRMED,
            'confirmed_at' => now(),
            'closed_at' => null,
            'outcome_note' => null,
            'hold_expires_at' => null,
        ])->save();

        DB::afterCommit(fn () => $this->announce($locked->fresh()));
    }

    // Keeps the booking's own table, or moves it to another free one; false when nothing fits any more.
    private function keepTable(Reservation $reservation): bool
    {
        $shop = Shop::query()->withoutGlobalScopes()->findOrFail($reservation->shop_id);
        $ends = $reservation->endsAt();
        $booked = $this->liveBookings($shop, $reservation->reserved_for->copy()->subDay(), $ends->copy()->addDay())
            ->reject(fn (Reservation $other) => $other->id === $reservation->id);

        if ($reservation->restaurant_table_id !== null && ! $this->clashes($booked, (int) $reservation->restaurant_table_id, $reservation->reserved_for, $ends)) {
            return true;
        }

        $other = $this->tablesFor($shop, (int) $reservation->party_size)
            ->first(fn (RestaurantTable $table) => ! $this->clashes($booked, $table->id, $reservation->reserved_for, $ends));
        if ($other === null) {
            return false;
        }
        $reservation->forceFill(['restaurant_table_id' => $other->id])->save();

        return true;
    }

    /* ------------------------------------------------------------ cancel */

    // The guest's own cancellation: free before paying, refunded in full inside the cancellation window.
    public function cancelByGuest(Reservation $reservation): bool
    {
        $shop = Shop::query()->withoutGlobalScopes()->findOrFail($reservation->shop_id);
        $settings = OnlineBooking::settings($shop);

        if (! in_array($reservation->status, [Reservation::REQUESTED, Reservation::CONFIRMED], true)) {
            throw new RuntimeException('Only upcoming bookings can be cancelled.');
        }

        if ($reservation->status === Reservation::CONFIRMED) {
            $hoursLeft = OnlineBooking::now($shop)->diffInMinutes($reservation->reserved_for, false) / 60;
            if ($hoursLeft < (int) $settings['cancel_hours']) {
                throw new RuntimeException(sprintf(
                    'Bookings can be cancelled online up to %d hours before. Please call the restaurant.',
                    (int) $settings['cancel_hours'],
                ));
            }
        }

        $reservation->forceFill([
            'status' => Reservation::CANCELLED,
            'outcome_note' => 'Cancelled by the guest online.',
            'closed_at' => now(),
            'hold_expires_at' => null,
        ])->save();

        $this->failOpenIntents($reservation, 'Booking cancelled');

        return $this->refundAll($reservation, 'Cancelled by the guest');
    }

    // The restaurant cancelled: any payment still open on the booking can no longer be used.
    public function releaseHold(Reservation $reservation): void
    {
        $reservation->forceFill(['hold_expires_at' => null])->save();
        $this->failOpenIntents($reservation, 'Booking cancelled');
    }

    // Unpaid holds past their time (plus grace) give the table back; run every minute by the scheduler.
    public function expireHolds(): int
    {
        $stale = Reservation::allShops()
            ->where('status', Reservation::REQUESTED)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->limit(500)
            ->get();

        foreach ($stale as $reservation) {
            $reservation->forceFill([
                'status' => Reservation::CANCELLED,
                'outcome_note' => self::EXPIRED_NOTE,
                'closed_at' => now(),
                'hold_expires_at' => null,
            ])->save();
            $this->failOpenIntents($reservation, 'Payment window expired');
        }

        return $stale->count();
    }

    /* ----------------------------------------------------------- refunds */

    // Refunds every paid deposit on the booking; true when something was refunded.
    public function refundAll(Reservation $reservation, string $reason): bool
    {
        $refunded = false;
        foreach ($this->intents($reservation)->where('status', PaymentIntent::PAID) as $intent) {
            $refunded = $this->refundIntent($intent, $reason) || $refunded;
        }

        return $refunded;
    }

    private function refundIntent(PaymentIntent $intent, string $reason): bool
    {
        $amount = $this->payments->refundableAmount($intent);
        if ($amount <= 0) {
            return false;
        }

        try {
            $this->payments->refund($intent, $amount, $reason);

            return true;
        } catch (Throwable $e) {
            // Left paid, so it shows in Online Payments for the restaurant to refund by hand.
            report($e);

            return false;
        }
    }

    private function refundLater(PaymentIntent $intent, string $reason): void
    {
        DB::afterCommit(fn () => $this->refundIntent($intent->fresh(), $reason));
    }

    private function failOpenIntents(Reservation $reservation, string $reason): void
    {
        foreach ($this->intents($reservation)->whereIn('status', [PaymentIntent::PENDING, PaymentIntent::AUTHORIZED]) as $intent) {
            $this->payments->fail($intent, $reason);
        }
    }

    /** @return Collection<int, PaymentIntent> */
    public function intents(Reservation $reservation): Collection
    {
        return PaymentIntent::allShops()
            ->where('payable_type', $reservation->getMorphClass())
            ->where('payable_id', $reservation->id)
            ->latest('id')
            ->get();
    }

    /* ----------------------------------------------------------- helpers */

    /** @throws RuntimeException */
    private function assertBookable(Shop $shop, string $date, int $partySize): array
    {
        if (! OnlineBooking::isOpen($shop)) {
            throw new RuntimeException('Online table booking is not available here right now.');
        }

        $settings = OnlineBooking::settings($shop);
        $day = Carbon::hasFormat($date, 'Y-m-d') ? Carbon::createFromFormat('!Y-m-d', $date) : null;
        if ($day === null || $day->format('Y-m-d') !== $date) {
            throw new RuntimeException('Please choose a valid date.');
        }

        $today = OnlineBooking::now($shop)->startOfDay();
        if ($day->lt($today)) {
            throw new RuntimeException('That date has already passed.');
        }
        if ($day->gt($today->copy()->addDays((int) $settings['window_days']))) {
            throw new RuntimeException(sprintf('Bookings open %d days ahead. Please choose an earlier date.', (int) $settings['window_days']));
        }
        if (! in_array($day->dayOfWeek, array_map('intval', $settings['days']), true)) {
            throw new RuntimeException('We do not take bookings on that day.');
        }
        if ($partySize < 1 || $partySize > (int) $settings['max_party']) {
            throw new RuntimeException(sprintf('For more than %d guests, please call the restaurant.', (int) $settings['max_party']));
        }

        return $settings;
    }

    /** @return array<int, string> start times, "HH:MM" */
    private function times(array $settings): array
    {
        $times = [];
        $cursor = Carbon::createFromFormat('!H:i', $settings['opens']);
        $last = Carbon::createFromFormat('!H:i', $settings['closes']);
        while ($cursor->lte($last)) {
            $times[] = $cursor->format('H:i');
            $cursor->addMinutes(max(15, (int) $settings['slot_minutes']));
        }

        return $times;
    }

    /** @return Collection<int, RestaurantTable> smallest table first, so big tables stay free for big parties */
    private function tablesFor(Shop $shop, int $partySize): Collection
    {
        return RestaurantTable::query()->withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->where('is_active', true)
            ->where('capacity', '>=', $partySize)
            ->orderBy('capacity')
            ->orderBy('name')
            ->get();
    }

    // Bookings that hold a table in the range; an online hold whose time has run out no longer does.
    private function liveBookings(Shop $shop, Carbon $from, Carbon $to): Collection
    {
        return Reservation::allShops()
            ->where('shop_id', $shop->id)
            ->whereIn('status', Reservation::HOLDS_A_TABLE)
            ->whereNotNull('restaurant_table_id')
            ->where('reserved_for', '>=', $from)
            ->where('reserved_for', '<', $to)
            ->get()
            ->reject(fn (Reservation $r) => $r->status === Reservation::REQUESTED
                && $r->hold_expires_at !== null
                && $r->hold_expires_at->isPast())
            ->values();
    }

    private function clashes(Collection $booked, int $tableId, Carbon $at, Carbon $ends): bool
    {
        return $booked->contains(fn (Reservation $r) => (int) $r->restaurant_table_id === $tableId
            && $r->reserved_for->lt($ends)
            && $r->endsAt()->gt($at));
    }

    // Inside the caller's transaction: the shop's candidate tables are locked so two guests cannot take the last one.
    private function freeTable(Shop $shop, int $partySize, Carbon $at, int $minutes): ?RestaurantTable
    {
        $tables = RestaurantTable::query()->withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->where('is_active', true)
            ->where('capacity', '>=', $partySize)
            ->orderBy('capacity')
            ->orderBy('name')
            ->lockForUpdate()
            ->get();

        $ends = $at->copy()->addMinutes($minutes);
        $booked = $this->liveBookings($shop, $at->copy()->subDay(), $ends->copy()->addDay());

        return $tables->first(fn (RestaurantTable $table) => ! $this->clashes($booked, $table->id, $at, $ends));
    }

    // Tells the restaurant (bell) and the guest (e-mail); a failure here never undoes the booking.
    private function announce(Reservation $reservation): void
    {
        try {
            $shop = Shop::query()->withoutGlobalScopes()->find($reservation->shop_id);
            if ($shop !== null) {
                $this->alerts->raise(
                    $shop,
                    Alert::RESERVATION,
                    sprintf('New table booking · %s', $reservation->guest_name),
                    'reservation:'.$reservation->id,
                    sprintf('%d guests · %s%s', $reservation->party_size, $reservation->reserved_for->format('D j M, g:i a'),
                        (float) $reservation->deposit_amount > 0 ? ' · ₹'.number_format((float) $reservation->deposit_amount, 2).' paid' : ''),
                    route('admin.reservations.index', ['day' => $reservation->reserved_for->toDateString()], false),
                    $reservation,
                    can: 'dining.reservations.view',
                    expiresAt: $reservation->endsAt(),
                );
            }
        } catch (Throwable $e) {
            report($e);
        }

        if (filled($reservation->guest_email)) {
            try {
                Mail::to($reservation->guest_email, $reservation->guest_name)->queue(new TableBookedMail($reservation));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
