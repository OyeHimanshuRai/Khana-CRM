<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which shops a user may work in.
     *
     * This pivot *is* the authorisation the SRS asks for: "shop users must
     * not access another shop's data unless explicitly authorized". A row
     * here is that authorisation. Super Admin is the one exception and
     * bypasses the pivot entirely (see App\Support\CurrentShop).
     */
    public function up(): void
    {
        Schema::create('shop_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Which shop this user lands in after signing in.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->unique(['shop_id', 'user_id']);
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_user');
    }
};
