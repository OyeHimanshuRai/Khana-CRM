<?php

namespace App\Console\Commands;

use App\Services\OnlineBookingService;
use Illuminate\Console\Command;

// Gives back tables held for online bookings whose deposit was never paid.
class ExpireBookingHolds extends Command
{
    protected $signature = 'reservations:expire-holds';

    protected $description = 'Release tables held for online bookings whose deposit was not paid in time';

    public function handle(OnlineBookingService $bookings): int
    {
        $count = $bookings->expireHolds();

        if ($count > 0) {
            $this->info("Released {$count} unpaid booking hold(s).");
        }

        return self::SUCCESS;
    }
}
