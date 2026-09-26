<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Units of measure: KG, LTR, PCS, BAG, ML.
     *
     * allow_decimal is the one that matters at the counter. 2.5 kg of urea
     * is an ordinary sale; 2.5 sprayers is a typo, and the POS should refuse
     * it rather than round it.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();

            $table->string('name', 60);
            $table->string('code', 12)->unique();

            $table->boolean('allow_decimal')->default(false);

            // How many decimals to show and store, when they are allowed.
            $table->unsignedTinyInteger('precision')->default(0);

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['sort_order', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
