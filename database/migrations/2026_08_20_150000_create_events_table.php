<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            // The headline, and the event/exhibition it belongs to.
            $table->string('title', 180);
            $table->string('name', 180)->nullable();

            /*
             | Free text, not a time column: real listings say things like
             | "10:00 AM - 6:00 PM" or "Daily, 11am onwards", which no time
             | type stores without losing the wording.
             */
            $table->string('timing', 120)->nullable();

            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();

            $table->string('booth_no', 60)->nullable();

            // Path on the `public` disk, e.g. "events/ab12….jpg".
            $table->string('image_path')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            // The listing's default ordering: soonest first.
            $table->index(['from_date', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
