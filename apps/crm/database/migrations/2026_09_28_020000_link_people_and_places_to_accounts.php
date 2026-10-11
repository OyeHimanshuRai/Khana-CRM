<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Khana\Kit\Support\Ulid;

return new class extends Migration
{
    /**
     * The ids Accounts knows these rows by.
     *
     * Who a person is, which business they belong to and which outlets it
     * runs are Accounts' answers now. CRM keeps its own rows for all three -
     * every foreign key in this schema points at them - and this is the
     * column that ties each one to the Accounts record it mirrors:
     *
     *   tenants.ulid   Accounts' tenant id
     *   shops.ulid     Accounts' outlet id
     *   users.ulid     Accounts' user id
     *   roles.key      the role key Accounts hands over at sign-in
     *                  ("cashier"), matched to the role here that carries
     *                  the rights
     *
     * Existing rows get a fresh ULID so every row has one; a row that
     * Accounts later signs somebody into is re-pointed at Accounts' id by
     * App\Auth\AccountsSignIn. Nullable and unique rather than NOT NULL, for
     * the same SQLite reason as the menu's ulid column.
     */
    public function up(): void
    {
        foreach (['tenants', 'shops', 'users'] as $table) {
            if (! Schema::hasColumn($table, 'ulid')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('ulid', 26)->nullable()->after('id')->unique());
            }

            DB::table($table)->whereNull('ulid')->orderBy('id')->select('id')
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update(['ulid' => Ulid::generate()]);
                    }
                });
        }

        $roles = config('permission.table_names.roles', 'roles');

        if (Schema::hasTable($roles) && ! Schema::hasColumn($roles, 'key')) {
            Schema::table($roles, fn (Blueprint $t) => $t->string('key', 40)->nullable()->after('name'));
        }

        // Roles that already exist get a key from their name; the seeder
        // then gives the presets the keys Accounts uses.
        if (Schema::hasTable($roles)) {
            foreach (DB::table($roles)->whereNull('key')->get(['id', 'name']) as $role) {
                DB::table($roles)->where('id', $role->id)->update(['key' => Str::limit(Str::slug($role->name, '_'), 40, '')]);
            }
        }
    }

    public function down(): void
    {
        foreach (['tenants', 'shops', 'users'] as $table) {
            if (Schema::hasColumn($table, 'ulid')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropUnique("{$table}_ulid_unique");
                    $t->dropColumn('ulid');
                });
            }
        }

        $roles = config('permission.table_names.roles', 'roles');

        if (Schema::hasColumn($roles, 'key')) {
            Schema::table($roles, fn (Blueprint $t) => $t->dropColumn('key'));
        }
    }
};
