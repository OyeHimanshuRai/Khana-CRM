<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each person wants to hear about, and how.
     *
     * A JSON column on the user rather than a table of rows: it is read on
     * every admin page (the bell and the kitchen board both need it), it is
     * only ever read and written whole, and it belongs to one person. Null
     * means "never changed anything", which reads as everything on - see
     * App\Support\NotificationPreferences.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('all_shops_view');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
