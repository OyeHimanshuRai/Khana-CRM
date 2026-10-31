<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The Project Owner's sidebar switch for a restaurant's own menus. On for every
| account that exists today, so nobody's sidebar changes on upgrade. It only
| ever decides what the sidebar offers, never what anybody may open.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('show_shop_menus')->default(true)->after('all_shops_view');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('show_shop_menus');
        });
    }
};
