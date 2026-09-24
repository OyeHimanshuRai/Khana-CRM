<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whose audit trail (SRS 10, 21).
     *
     * ------------------------------------------------------------------------
     * An audit log everybody could read
     * ------------------------------------------------------------------------
     *
     * `settings.activity_logs.*` is granted to a Tenant Owner deliberately -
     * an owner has to be able to see who voided which bill, and that is the
     * screen that answers it. What nobody intended is that the table had no
     * owner column, so the screen answered it for every business on the
     * platform: staff names, email addresses, IP addresses, and a description
     * of every action another restaurant took.
     *
     * Worse than a read: the same right prunes. One customer clearing their
     * own log to ninety days took everybody's with it.
     *
     * ------------------------------------------------------------------------
     * Backfilled from who did it
     * ------------------------------------------------------------------------
     *
     * Existing rows are handed to the actor's company, which is the only
     * honest answer available - the log records who, and a user belongs to
     * one business. Rows written by nobody (the scheduler, the installer, a
     * console command) and rows by a Super Admin stay null: they are platform
     * events and belong to the platform, which is exactly who can still see
     * them.
     */
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs') || Schema::hasColumn('activity_logs', 'tenant_id')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                // The log outlives the company it describes. An audit trail
                // that disappeared with the account it audits would be the
                // one thing an auditor asks for after a dispute.
                ->constrained('tenants')
                ->nullOnDelete();

            $table->index(['tenant_id', 'created_at']);
        });

        DB::table('activity_logs')
            ->whereNull('tenant_id')
            ->whereNotNull('user_id')
            ->update([
                'tenant_id' => DB::raw('(select tenant_id from users where users.id = activity_logs.user_id)'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('activity_logs') || ! Schema::hasColumn('activity_logs', 'tenant_id')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            // The key first: MySQL satisfies it with the index below, and
            // dropping that while the key needs it fails with a 1553.
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropColumn('tenant_id');
        });
    }
};
