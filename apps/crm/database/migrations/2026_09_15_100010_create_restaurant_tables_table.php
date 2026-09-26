<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables people sit at.
     *
     * Named `restaurant_tables`, not `tables` as the requirements §16 list
     * spells it. "Table" is what every schema tool, every migration and half
     * the framework already means by the word; a model called `Table` and a
     * table called `tables` makes every sentence in this codebase ambiguous
     * for the sake of four characters. The concept is identical.
     *
     * Status is stored, not derived. §5 wants the dashboard to count
     * Available / Occupied / Reserved / Billing / Cleaning, and three of
     * those five are facts about the room rather than about any order:
     * nothing in the order tables can tell you a table is being wiped down.
     *
     * Position is kept here too. §7 asks for a visual floor plan with
     * drag/drop placement, and a coordinate pair per table is all that needs
     * - a separate layout table would be one join for two integers.
     */
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            /*
             | Restricted, not cascaded. Deleting a floor that still has
             | tables would take their QR codes and their order history with
             | it; the controller moves or refuses instead.
             */
            $table->foreignId('floor_id')->constrained()->restrictOnDelete();

            // "12", "A4", "Cabin 2" - whatever the restaurant calls out.
            $table->string('name', 40);

            /*
             | The stable identifier, used in the QR payload and printed on
             | the KOT. Unique per branch and never reused, so a photographed
             | QR from last season cannot resolve to somebody else's table.
             */
            $table->string('code', 24);

            $table->unsignedSmallInteger('capacity')->default(4);

            /*
             | available - free to seat
             | occupied  - guests seated, order open
             | reserved  - held for a booking
             | billing   - bill printed, payment being settled
             | cleaning  - being reset between covers
             |
             | A string rather than an enum: §21 has a reservation manager in
             | the future set, and an enum column is a migration every time
             | that list grows. App\Models\RestaurantTable::STATUSES is the
             | list, and validation reads it from there.
             */
            $table->string('status', 20)->default('available');

            // Free-text for the front of house: "window seat", "booked 8pm".
            $table->string('note', 250)->nullable();

            /*
             | Floor-plan coordinates, as a percentage of the canvas at three
             | decimals rather than pixels - the plan is rendered at whatever
             | width the screen gives it, and pixels from a designer's laptop
             | would put the rooftop through a wall on a tablet.
             */
            $table->decimal('pos_x', 6, 3)->default(0);
            $table->decimal('pos_y', 6, 3)->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['shop_id', 'code']);
            $table->unique(['floor_id', 'name']);
            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'is_active']);
            $table->index(['floor_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
