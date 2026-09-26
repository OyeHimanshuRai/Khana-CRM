<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| CRM's and ERP's read-only copy of what Accounts owns: the businesses, their
| outlets, and which products each outlet has bought. Written only by the
| accounts.* event handlers, and read by every tenancy and entitlement check -
| so a product keeps working while Accounts is down.
|
| Not created in Accounts itself, which holds the originals.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('khana_tenants', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('status', 20);
            // The occurred_at of the event that last changed the row, so an
            // older event arriving late cannot undo a newer one.
            $table->dateTime('changed_at');
        });

        Schema::create('khana_outlets', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26)->index();
            $table->string('name');
            $table->string('code', 12);
            $table->string('gstin', 15)->nullable();
            $table->string('fssai', 14)->nullable();
            $table->json('address');
            $table->char('state_code', 2);
            $table->string('timezone', 64);
            $table->char('day_cutoff', 5);
            $table->string('status', 20);
            $table->dateTime('changed_at');
        });

        Schema::create('khana_entitlements', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->string('product', 10);
            $table->string('state', 12);
            $table->string('plan', 40);
            $table->json('limits');
            $table->dateTime('valid_until')->nullable();
            $table->dateTime('changed_at');

            $table->unique(['outlet_id', 'product']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('khana_entitlements');
        Schema::dropIfExists('khana_outlets');
        Schema::dropIfExists('khana_tenants');
    }
};
