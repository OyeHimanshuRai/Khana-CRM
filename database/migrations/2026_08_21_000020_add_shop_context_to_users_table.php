<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             | The shop this user is currently working in, remembered across
             | sessions and devices so a cashier does not have to re-pick
             | their till every morning.
             |
             | NULL is meaningful: it is "All shops", the consolidated view.
             | Only a user with more than one accessible shop can select it,
             | and even then the query scope still narrows to the shops they
             | may see - see App\Models\Concerns\BelongsToShop.
             */
            $table->foreignId('current_shop_id')
                ->nullable()
                ->after('is_active')
                ->constrained('shops')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_shop_id');
        });
    }
};
