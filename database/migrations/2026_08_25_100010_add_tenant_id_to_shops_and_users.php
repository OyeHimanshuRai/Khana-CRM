<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hang the existing shops and users off a tenant.
     *
     * Both columns are nullable, and deliberately so:
     *
     *   shops.tenant_id  - null only for the moment between this migration
     *                      and TenantSeeder adopting the existing rows. A
     *                      NOT NULL column would refuse to add itself to a
     *                      table that already has rows and no tenant to
     *                      point them at.
     *
     *   users.tenant_id  - null is a real, permanent state: it means Super
     *                      Admin, the account that belongs to no single
     *                      business and oversees all of them. That is why
     *                      this column is not backfilled the way shops is.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained()
                // A tenant with branches cannot be deleted out from under
                // them; suspend it instead. See the tenants table comment.
                ->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();

            /*
             | Which tenant a Super Admin is currently looking at. Null means
             | "all tenants", the consolidated view. Kept on the account
             | rather than in the session so the choice survives a new device
             | - the same reasoning as current_shop_id beside it.
             */
            $table->foreignId('current_tenant_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('tenants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_tenant_id');
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
