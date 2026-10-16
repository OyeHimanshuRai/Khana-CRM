<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Only the untouched seeded default is renamed; a name an admin typed stays.
    public function up(): void
    {
        DB::table('settings')
            ->whereIn('key', ['company_name', 'meta_title_home', 'mail_from_name'])
            ->where('value', 'Tiara Softwares')
            ->update(['value' => 'Restro One', 'updated_at' => now()]);

        Cache::forget('settings.map');
    }

    public function down(): void
    {
        // Irreversible on purpose: nothing records which rows were the default.
    }
};
