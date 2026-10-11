<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the nightly checks found, one row per check per night.
     *
     * Events between the services can be lost, and a copy can drift from
     * what it copies. `crm:nightly` compares CRM with the service that owns
     * each fact, puts right what it safely can, and writes down what it
     * found here - so "did last night's check pass?" is a row somebody can
     * read rather than a log line somebody has to find.
     *
     *   check          reference, balances, availability
     *   status         ok        nothing differed
     *                  repaired  something differed and was put right
     *                  problem   something differed and was left for a person
     *                  skipped   the check does not apply here, or is not
     *                            built yet
     *   found          how many things differed
     *   repaired       how many of them were put right
     *   detail         what, in plain words
     *
     * tenant_id and outlet_id are Accounts' ids (tenants.ulid, shops.ulid),
     * not this database's numbers: the row describes something the three
     * services agree or disagree about, and those are the ids they share. A
     * check about a whole business names no outlet; one that could not be
     * run at all names neither.
     *
     * Unique per outlet, day and check, so a second run the same night
     * replaces its row rather than adding one. A unique index does not hold
     * NULLs apart, so the rows that name no outlet are kept single by the
     * command that writes them - see App\Console\Commands\RunNightlyChecks.
     */
    public function up(): void
    {
        Schema::create('nightly_checks', function (Blueprint $table) {
            $table->id();

            $table->char('tenant_id', 26)->nullable();
            $table->char('outlet_id', 26)->nullable();
            $table->date('business_date');
            $table->string('check', 40);
            $table->string('status', 12);
            $table->unsignedInteger('found')->default(0);
            $table->unsignedInteger('repaired')->default(0);
            $table->text('detail')->nullable();
            $table->dateTime('ran_at');

            $table->unique(['outlet_id', 'business_date', 'check'], 'nightly_checks_outlet_day_check_unique');
            $table->index(['tenant_id', 'business_date', 'check']);
            $table->index(['status', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nightly_checks');
    }
};
