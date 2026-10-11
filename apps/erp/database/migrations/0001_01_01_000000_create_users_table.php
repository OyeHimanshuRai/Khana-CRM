<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people who work in ERP.
 *
 * A row here is a mirror, not a source: Khana Accounts owns the person, their
 * business, their role in ERP and the outlets they may open, and
 * App\Auth\AccountsSignIn copies all of that down at every sign-in. There is
 * no password - nobody signs in here directly.
 *
 * `tenant_id` and the outlet ids are Accounts' ULIDs, the same keys the
 * reference copy (khana_tenants, khana_outlets) is held under, so the kit's
 * tenancy scopes work on them as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Accounts' id for this person.
            $table->char('ulid', 26)->unique();
            // Null for platform staff, who belong to no business.
            $table->char('tenant_id', 26)->nullable()->index();
            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->string('mobile', 20)->nullable();
            // One of App\Support\Roles::PRESETS. Null for platform staff.
            $table->string('role_key', 40)->nullable();
            $table->boolean('is_platform_admin')->default(false);
            // The outlets Accounts gave this person in ERP.
            $table->json('outlet_ids');
            // Where the outlet switcher was left.
            $table->char('current_tenant_id', 26)->nullable();
            $table->char('current_outlet_id', 26)->nullable();
            $table->boolean('all_outlets')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
