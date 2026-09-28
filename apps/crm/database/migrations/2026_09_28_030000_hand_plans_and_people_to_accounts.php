<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Take out what CRM sold, and whom it let in.
     *
     * Plans, subscriptions and their payments are Khana Accounts' (the
     * architecture doc's ownership table): it sells each outlet a product and
     * tells CRM the outcome as accounts.entitlement.changed, which the kit
     * keeps in `khana_entitlements`. Signing up a business, suspending one,
     * and adding, editing or switching off its staff are Accounts' screens
     * too. The code these served has gone; this is for the databases that
     * still hold them.
     *
     * Removed:
     *
     *   plans, subscriptions, subscription_payments
     *   payment_intents   the rows that paid for a subscription, and their
     *                     refunds - nothing can load what they paid for, and
     *                     the online-payments screens would trip over them.
     *                     A table's payments stay.
     *   tenants           suspended_at, suspend_reason: only the removed
     *                     suspend screen and subscription sweep wrote them.
     *                     is_active stays; it is still read.
     *   alerts            ones pointing at screens that no longer exist
     *   permissions       settings.tenants, .plans, .subscriptions, .users
     *                     and .sessions
     *
     * Kept, because CRM still points at them: tenants, shops and users, which
     * mirror Accounts through their ulid columns.
     *
     * Guarded throughout, so it is a no-op on a database already past it.
     * Irreversible on purpose: there is no code left for a schema to serve.
     */
    public function up(): void
    {
        $this->dropSubscriptionIntents();

        Schema::withoutForeignKeyConstraints(function () {
            foreach (['subscription_payments', 'subscriptions', 'plans'] as $table) {
                Schema::dropIfExists($table);
            }
        });

        $this->dropColumns('tenants', ['suspended_at', 'suspend_reason']);

        if (Schema::hasTable('alerts')) {
            DB::table('alerts')
                ->where(function ($query) {
                    foreach (['billing', 'subscription', 'plans', 'tenants', 'users/'] as $screen) {
                        $query->orWhere('link', 'like', "/admin/{$screen}%");
                    }
                })
                ->delete();
        }

        $this->dropPermissions();
    }

    public function down(): void
    {
        // Nothing to restore: the code these tables and columns served is gone.
    }

    /**
     * Online payments that were a restaurant paying for its own account.
     *
     * By class name, because that is what the morph column holds and the
     * class itself is gone. Refunds first, explicitly, rather than trusting a
     * cascade SQLite may not enforce.
     */
    private function dropSubscriptionIntents(): void
    {
        if (! Schema::hasTable('payment_intents')) {
            return;
        }

        $ids = DB::table('payment_intents')
            ->where('payable_type', 'App\\Models\\Subscription')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        if (Schema::hasTable('payment_refunds')) {
            DB::table('payment_refunds')->whereIn('payment_intent_id', $ids)->delete();
        }

        DB::table('payment_intents')->whereIn('id', $ids)->delete();
    }

    /** @param  list<string>  $columns */
    private function dropColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));

        if ($present !== []) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn($present));
        }
    }

    /**
     * The rights to screens that no longer exist.
     *
     * The role matrix is built from config and would never show them again,
     * and a grant nobody can see or revoke is not worth keeping.
     */
    private function dropPermissions(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $pivot = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $direct = config('permission.table_names.model_has_permissions', 'model_has_permissions');

        if (! Schema::hasTable($permissions)) {
            return;
        }

        $ids = DB::table($permissions)
            ->where(function ($query) {
                foreach (['tenants', 'plans', 'subscriptions', 'users', 'sessions'] as $submodule) {
                    $query->orWhere('name', 'like', "settings.{$submodule}.%");
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
};
