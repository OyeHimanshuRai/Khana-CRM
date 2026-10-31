<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Two kinds of plan, and modules only ever where the platform put them.
|
| `tenant_id` makes a plan custom: made for that one client, never on the price
| list. Restricted on delete, because losing it would quietly turn a private
| deal into a public plan.
|
| `modules` stops meaning "everything, including what comes next". A null list
| used to hand every future module to that plan the day it shipped; each plan
| now names its modules, so a new one joins nothing until the platform says so.
| The list here is what those plans could reach on the day of this change.
|
| A deleted plan also gives its code and slug back, so they can be used again.
*/
return new class extends Migration
{
    private const MODULES_TODAY = [
        'retail', 'pos', 'dining', 'kitchen', 'inventory',
        'barcode', 'purchase', 'customers', 'reports', 'storefront',
    ];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
        });

        DB::table('plans')->whereNull('modules')->update(['modules' => json_encode(self::MODULES_TODAY)]);

        DB::table('plans')->whereNotNull('deleted_at')->orderBy('id')->each(function (object $plan) {
            DB::table('plans')->where('id', $plan->id)->update([
                'code' => substr((string) $plan->code, 0, 24).'-deleted-'.$plan->id,
                'slug' => substr((string) $plan->slug, 0, 120).'-deleted-'.$plan->id,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        // Which lists were null cannot be told from the rest, so they stay named.
    }
};
