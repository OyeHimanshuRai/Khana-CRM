<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table bookings (SRS 5, 7, 16, 21).
     *
     * ------------------------------------------------------------------
     * A booking without a table is a real booking
     * ------------------------------------------------------------------
     *
     * `restaurant_table_id` is nullable, and that is the point rather than
     * an oversight. "Four people, Friday at eight" is a commitment the
     * restaurant has made before anybody decides which table they will sit
     * at - and most places decide that on the night, by looking at the room.
     *
     * Forcing a table at booking time would either invent a false
     * reservation on a table that then gets moved, or make it impossible to
     * take the booking at all. So the table is assigned when it is known,
     * which may be at the door.
     *
     * ------------------------------------------------------------------
     * The window, not just the time
     * ------------------------------------------------------------------
     *
     * A booking occupies a table for a stretch, and double-booking is the
     * one mistake that cannot be smoothed over at the door. So the duration
     * is stored and the overlap check is a real interval comparison - see
     * ReservationService::clashesFor.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            // Null until somebody decides which table. See above.
            $table->foreignId('restaurant_table_id')->nullable()
                ->constrained('restaurant_tables')->nullOnDelete();

            /*
             | A known regular, where there is one. Most bookings are a name
             | and a phone number and nothing else, which is why every guest
             | field below stands on its own rather than requiring a
             | customer record to be created for a party of two.
             */
            $table->foreignId('customer_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('guest_name', 120);
            $table->string('guest_mobile', 30)->nullable();
            $table->string('guest_email', 150)->nullable();

            $table->unsignedSmallInteger('party_size')->default(2);

            $table->dateTime('reserved_for');

            // How long the table is held. Ninety minutes is the usual
            // turn for a full meal; the restaurant can change it per booking.
            $table->unsignedSmallInteger('duration_minutes')->default(90);

            /*
             | requested | confirmed | seated | completed | no_show | cancelled
             |
             | Stored rather than derived, unlike a subscription's state -
             | and for the opposite reason. Nothing about the clock can tell
             | you whether a party turned up. "Eight o'clock has passed" and
             | "they did not come" are different facts, and only a person
             | knows the second one.
             */
            $table->string('status', 20)->default('requested')->index();

            // phone | walk_in | online | staff
            $table->string('source', 20)->default('phone');

            // "window seat if possible", "birthday - candle on the cake",
            // "wheelchair". SRS 7 calls these booking notes.
            $table->text('notes')->nullable();

            // What happened, for a cancellation or a no-show.
            $table->string('outcome_note', 190)->nullable();

            /*
             | The sitting this booking became. Set when the party is seated,
             | which is what turns a promise into a table with a bill on it.
             */
            $table->foreignId('table_session_id')->nullable()
                ->constrained('table_sessions')->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The two questions the screens ask: "what is booked tonight"
            // and "is this table free at eight".
            $table->index(['shop_id', 'reserved_for']);
            $table->index(['restaurant_table_id', 'reserved_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
