<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The businesses, their outlets and their people: what Accounts owns and CRM
| and ERP copy.
|
| Numeric ids inside Accounts; the ulid column is the id every other service
| knows a row by, and the only one that ever leaves this database.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            // active | suspended | closed. Suspended keeps every row; its
            // staff cannot sign in.
            $table->string('status', 20)->default('active');
            $table->string('status_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Short code used in document numbers, such as GF. Unique inside
            // the business only: two restaurants may both have a "GF".
            $table->string('code', 12);
            $table->string('gstin', 15)->nullable();
            $table->string('fssai', 14)->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city', 100);
            $table->char('pincode', 6)->nullable();
            // GST state code, "07" for Delhi. Decides intra- or inter-state tax.
            $table->char('state_code', 2);
            $table->string('phone', 20)->nullable();
            $table->string('timezone', 64)->default('Asia/Kolkata');
            // A bill at 01:30 with a 04:00 cutoff belongs to the evening before.
            $table->char('day_cutoff', 5)->default('04:00');
            // active | inactive | suspended
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            // Null for platform staff, who belong to no business.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            // A person signs in with either. At least one is always set.
            $table->string('email')->nullable()->unique();
            $table->string('mobile', 16)->nullable()->unique();
            // Null until an invited person sets one.
            $table->string('password')->nullable();
            // invited | active | deactivated
            $table->string('status', 20)->default('active');
            // Runs the business in Accounts: outlets, team, plan.
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_platform_admin')->default(false);
            // Where the person last was, remembered on the account so a
            // choice follows them between devices (see Kit\Tenancy\Actor).
            // Platform staff pick a business; everyone with more than one
            // outlet picks an outlet, or the consolidated "all" view.
            $table->foreignId('remembered_tenant_id')->nullable();
            $table->unsignedBigInteger('remembered_outlet_id')->nullable();
            $table->unsignedBigInteger('default_outlet_id')->nullable();
            $table->boolean('prefers_all_outlets')->default(false);
            // Two-step sign-in (TOTP). The secret is stored encrypted.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
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
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('outlets');
        Schema::dropIfExists('tenants');
    }
};
