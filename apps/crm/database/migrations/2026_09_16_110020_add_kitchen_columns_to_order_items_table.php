<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The KOT line: which station is cooking it and how far along it is (§9).
     *
     * ---------------------------------------------------------------------
     * Why the state lives on the line and not only on the order
     * ---------------------------------------------------------------------
     *
     * One ticket for a table is routinely two jobs in two rooms: the mojito
     * at the bar and the seekh at the tandoor. A single status on `orders`
     * cannot say "the drinks are poured and the kebab is still on". The
     * order's status stays, and is now *derived* from its lines - see
     * KitchenService::rollUp() - so the bill screen and the guest's phone
     * still have one number to read while each station bumps its own work.
     *
     * `kitchen_status` is nullable, and null means "this line is not the
     * kitchen's problem": every row written before this migration, and every
     * line of a web order for a shop with the kitchen module off. The KDS
     * feed keys off NOT NULL, so nothing historic is dragged onto the screen.
     *
     * The station is a snapshot taken when the order is placed, like the
     * dish's name and its price. Re-filing a dish tomorrow must not move a
     * ticket that is already on a pass - and nullOnDelete keeps the line
     * readable if the station itself is deleted later.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('kitchen_station_id')
                ->nullable()
                ->after('sku')
                ->constrained('kitchen_stations')
                ->nullOnDelete();

            $table->string('kitchen_status', 20)->nullable()->after('kitchen_station_id');

            // When a cook actually picked it up, and when it hit the pass.
            // Columns rather than a derived reading of the activity log,
            // because §9 wants a preparation-time report and "how long did
            // this dish take" should be one subtraction.
            $table->timestamp('kitchen_started_at')->nullable()->after('kitchen_status');
            $table->timestamp('kitchen_ready_at')->nullable()->after('kitchen_started_at');

            // The feed's query: one station's outstanding work, oldest first.
            $table->index(['kitchen_station_id', 'kitchen_status']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['kitchen_station_id', 'kitchen_status']);
            $table->dropForeign(['kitchen_station_id']);
            $table->dropColumn([
                'kitchen_station_id', 'kitchen_status',
                'kitchen_started_at', 'kitchen_ready_at',
            ]);
        });
    }
};
