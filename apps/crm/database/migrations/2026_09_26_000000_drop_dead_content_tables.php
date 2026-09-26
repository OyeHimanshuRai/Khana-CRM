<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Take out the content modules nothing ever showed.
     *
     * Blog posts, Instagram reels and posts, collections and events were
     * edited in the admin and rendered nowhere: the landing page reads
     * sliders, services, FAQs and its own sections, and none of these. They
     * were jewellery-era leftovers, five entries in a sidebar that was
     * already too long. Their `create` migrations went with the code, so a
     * fresh database never builds any of this; this migration is for the
     * databases that already have it.
     *
     * Guarded, so it is a no-op on a fresh install. Their permissions go too
     * - the role matrix is built from config and would never show them
     * again, and a grant nobody can see or revoke is not worth keeping.
     *
     * Irreversible on purpose: there is no code left for a schema to serve.
     */
    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            foreach (['collection_media', 'collections', 'events', 'blogs', 'reels', 'instagram_posts'] as $table) {
                Schema::dropIfExists($table);
            }
        });

        $permissions = config('permission.table_names.permissions', 'permissions');
        $pivot = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $direct = config('permission.table_names.model_has_permissions', 'model_has_permissions');

        if (! Schema::hasTable($permissions)) {
            return;
        }

        $ids = DB::table($permissions)
            ->where(function ($query) {
                foreach (['collections', 'events', 'blogs', 'reels', 'instagram'] as $submodule) {
                    $query->orWhere('name', 'like', "content.{$submodule}.%");
                }
            })
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Explicit rather than trusting cascades, which SQLite may not enforce.
        foreach ([$pivot, $direct] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('permission_id', $ids)->delete();
            }
        }

        DB::table($permissions)->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Nothing to restore: the code these tables served is gone.
    }
};
