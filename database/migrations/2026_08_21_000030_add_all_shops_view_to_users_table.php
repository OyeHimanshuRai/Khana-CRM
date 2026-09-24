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
             | Whether this account is looking at the consolidated view.
             |
             | current_shop_id alone could not say: NULL there meant both
             | "chose All shops" and "has never chosen anything", and the two
             | need opposite answers. A new cashier should open on their own
             | till; someone who deliberately picked All shops should stay
             | there. This flag is the difference.
             |
             | Only meaningful with more than one accessible shop - see
             | App\Support\CurrentShop, which re-checks that every request.
             */
            $table->boolean('all_shops_view')
                ->default(false)
                ->after('current_shop_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('all_shops_view');
        });
    }
};
