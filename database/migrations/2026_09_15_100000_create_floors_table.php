<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dining areas within one outlet: Ground Floor, Rooftop, AC Hall, Garden.
     *
     * Requirements §7 asks for "multiple dining areas/floors", and every table
     * belongs to exactly one. A separate table rather than a string on
     * `restaurant_tables` because the floor plan is a screen of its own - it
     * has an order, an active flag and eventually a background image, none of
     * which a repeated free-text column could carry.
     *
     * Shop-scoped, not tenant-scoped: a rooftop belongs to one branch. Two
     * branches of the same restaurant both having a "Ground Floor" is normal
     * and must not collide, which is what the composite unique below is for.
     */
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);

            /*
             | Printed on the KOT and the bill so the runner knows where to
             | take it - "RT-12" reads faster than "Rooftop / Table 12" on a
             | thermal slip. Short, and unique within the branch.
             */
            $table->string('code', 12);

            $table->string('description', 250)->nullable();

            /*
             | Deactivating a floor hides it from the POS and the floor plan
             | without deleting its tables - a rooftop closed for the monsoon
             | comes back in June with the same table numbers and the same QR
             | codes, which is the whole reason this is a flag and not a
             | delete.
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
        Schema::dropIfExists('floors');
    }
};
