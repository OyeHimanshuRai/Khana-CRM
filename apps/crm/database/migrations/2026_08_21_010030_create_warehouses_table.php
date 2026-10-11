<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Storage locations within a shop: the counter, the godown, the van.
     *
     * Shop-scoped, because a warehouse belongs to exactly one branch. Every
     * shop gets one marked is_default, so stock always has somewhere to land
     * even in a business that never thinks about locations.
     */
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('code', 20);

            $table->string('address', 250)->nullable();
            $table->string('city', 90)->nullable();
            $table->string('phone', 30)->nullable();

            // The one stock lands in when nothing else is chosen.
            $table->boolean('is_default')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Codes only have to be unique inside their own shop - two
            // branches may both call their back room "GODOWN".
            $table->unique(['shop_id', 'code']);
            $table->index(['shop_id', 'is_active']);
            $table->index(['shop_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
