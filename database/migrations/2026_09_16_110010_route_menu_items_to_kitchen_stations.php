<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auto-routing a KOT item to the station that cooks it (§9).
     *
     * Two columns, deliberately, because the answer is nearly always "the
     * whole category goes to the same place" and occasionally "except this
     * one". Setting a station on every one of four hundred dishes is how a
     * restaurant ends up with half of them routed and nobody noticing.
     *
     * The order the router reads them in - product, then category, then the
     * category's parent, then the shop's default - is in KitchenRouter, so
     * there is exactly one place that decides.
     *
     * nullOnDelete on both: deleting a station must not take the dish with
     * it. The dish falls back to the default station, which is a routing
     * mistake somebody can see and fix, not a lost menu item.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('kitchen_station_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('kitchen_stations')
                ->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('kitchen_station_id')
                ->nullable()
                ->after('category_id')
                ->constrained('kitchen_stations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['kitchen_station_id']);
            $table->dropColumn('kitchen_station_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['kitchen_station_id']);
            $table->dropColumn('kitchen_station_id');
        });
    }
};
