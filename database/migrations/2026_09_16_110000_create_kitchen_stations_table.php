<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a dish is actually cooked (§9).
     *
     * Bar, Main Kitchen, Tandoor, Bakery, Dessert. A restaurant with one
     * kitchen has one row and never thinks about it again; one with a tandoor
     * across the corridor needs the biryani ticket to stop appearing on the
     * tandoor's screen, and that is what this exists for.
     *
     * Shop-scoped like `floors`, for the same reason: a tandoor is a physical
     * thing in one branch. Two branches both having a "Main Kitchen" is
     * normal, which is what the composite uniques below allow for.
     */
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);

            /*
             | Printed on the KOT slip and shown on the station tab. Short,
             | because a cook reads it across a hot line: "TAN", not "Tandoor
             | & Grill Section".
             */
            $table->string('code', 12);

            $table->string('description', 250)->nullable();

            /*
             | The station that takes anything nobody routed.
             |
             | Without one, a dish added at eight on a Friday by somebody who
             | did not think about stations would be cooked by nobody and
             | nobody would see the ticket - the worst failure this screen can
             | have. Exactly one per shop; KitchenStation::makeDefault()
             | enforces that, because a partial unique index is not portable.
             */
            $table->boolean('is_default')->default(false);

            /*
             | How long this station expects to take, in minutes. Drives the
             | amber/red on the ticket card: a bar that has had a drink order
             | for six minutes is late, a tandoor that has had a raan for six
             | minutes has barely started.
             */
            $table->unsignedSmallInteger('prep_minutes')->default(15);

            /*
             | Switched off rather than deleted when a section closes for the
             | season. Its routing stays intact and its tickets stay readable;
             | new items fall through to the default station.
             */
            $table->boolean('is_active')->default(true);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['shop_id', 'code']);
            $table->unique(['shop_id', 'name']);
            $table->index(['shop_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_stations');
    }
};
