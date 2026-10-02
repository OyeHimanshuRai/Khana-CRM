<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link with CRM and Accounts, checked.
 *
 * Events carry everything live, and an event can be lost. So each night,
 * after an outlet's day has closed, ERP compares what it holds with what CRM
 * and Accounts hold and puts right what differs. This is the record of what
 * each night found - kept so that a person can read it, and so that a check
 * that finished is not run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlet_settings', function (Blueprint $table) {
            // When ERP last brought its copy of the menu in line with CRM's.
            // Null for an outlet that never has: one with no CRM, or one
            // whose first pull is still to come.
            $table->dateTime('menu_synced_at')->nullable();
        });

        Schema::create('nightly_checks', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            // The outlet's trading day that was checked.
            $table->date('business_date');
            // sales | menu | reference | balances
            $table->string('check', 12);
            // ok | repaired | problem | skipped. All but `problem` are final;
            // a problem is tried again on the next run and this row updated.
            $table->string('status', 10);
            // How many differences were found, and how many were put right.
            $table->unsignedInteger('found')->default(0);
            $table->unsignedInteger('repaired')->default(0);
            // What was found, in words a manager can read.
            $table->text('detail')->nullable();
            $table->dateTime('ran_at');
            $table->timestamps();

            $table->unique(['outlet_id', 'business_date', 'check']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nightly_checks');

        Schema::table('outlet_settings', fn (Blueprint $table) => $table->dropColumn('menu_synced_at'));
    }
};
