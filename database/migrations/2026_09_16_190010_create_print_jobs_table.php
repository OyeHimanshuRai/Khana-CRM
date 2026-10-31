<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every ticket and bill that was printed (SRS 6, 8.5).
     *
     * ------------------------------------------------------------------
     * The reprint is the reason this table exists
     * ------------------------------------------------------------------
     *
     * §6 asks for "KOT print and reprint with audit trail", and the audit is
     * about the reprint rather than the print. A first KOT is ordinary. A
     * second copy of the same KOT is how a dish gets made twice, how a
     * cancelled item comes back, and - occasionally - how somebody takes food
     * out of a kitchen that nobody billed for.
     *
     * So a reprint records who asked, when, and why. `is_reprint` is not
     * derived from counting rows: whether a print is a reprint is a fact
     * about intent, and a printer that jammed on the first attempt did not
     * print anything to be a copy of.
     *
     * ------------------------------------------------------------------
     * Failures are kept
     * ------------------------------------------------------------------
     *
     * A network printer that was switched off leaves a `failed` row with the
     * reason on it. That is the whole value of the table on the evening it
     * matters: "the tandoor never got the ticket" is a question the log can
     * answer, and a job that quietly vanished cannot.
     */
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            /*
             | Null when the job was a browser print, which has no printer
             | row - the operating system chose the machine and this system
             | never learns which. Recording the attempt is still worth it:
             | it is what makes a reprint countable.
             */
            $table->foreignId('printer_id')->nullable()
                ->constrained('printers')->nullOnDelete();

            // What was printed: an Order, an Invoice, anything else later.
            $table->nullableMorphs('printable');

            // kot | bill | label | report
            $table->string('kind', 20)->index();

            /*
             | The station this copy was for, where it was a KOT. A ticket
             | split across a tandoor and a bar prints twice, and the two rows
             | are not duplicates.
             */
            $table->foreignId('kitchen_station_id')->nullable()
                ->constrained('kitchen_stations')->nullOnDelete();

            // queued | sent | failed
            $table->string('status', 20)->default('queued')->index();

            $table->unsignedTinyInteger('copies')->default(1);

            $table->boolean('is_reprint')->default(false);

            // Why somebody asked for another copy. The point of the audit.
            $table->string('reason', 190)->nullable();

            // What went wrong, in the provider's own words, for the one
            // evening somebody needs it.
            $table->string('error', 255)->nullable();

            $table->unsignedInteger('bytes')->nullable();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['printable_type', 'printable_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
