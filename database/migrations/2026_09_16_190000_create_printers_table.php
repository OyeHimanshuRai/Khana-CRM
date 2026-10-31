<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The machines that put ink on paper (SRS 4, 6, 8, 21).
     *
     * ------------------------------------------------------------------
     * Why a registry rather than just browser printing
     * ------------------------------------------------------------------
     *
     * Browser printing works and is what this system has done so far: open a
     * print page, hit Ctrl-P, choose a printer. It is fine for a bill handed
     * over at a counter by the person who pressed the button.
     *
     * It is no good for a kitchen. A KOT has to come out of the printer
     * beside the tandoor while the till is at the front of the shop, with
     * nobody choosing anything - and the dialog that asks which printer is
     * exactly the thing that cannot be there.
     *
     * So a printer is a row: what it is for, where it is, and which station
     * it serves. KitchenRouter already decides which station a dish belongs
     * to; this decides which box that station's paper comes out of.
     *
     * ------------------------------------------------------------------
     * Two drivers, and `browser` is not a lesser one
     * ------------------------------------------------------------------
     *
     * `browser` keeps today's behaviour exactly: a print page and the
     * operating system's dialog. `network` opens a socket to the printer and
     * writes ESC/POS, which is what every thermal printer in this market
     * speaks over port 9100.
     *
     * A restaurant with no network printers configures nothing and loses
     * nothing.
     */
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('name', 90);
            $table->string('code', 40);

            // kot | bill | label | report
            $table->string('kind', 20)->default('kot')->index();

            // browser | network
            $table->string('driver', 20)->default('browser');

            /*
             | Which kitchen station's paper this is. Null means "not tied to
             | one" - the right answer for a bill printer, and for a single
             | kitchen printer that takes everything.
             */
            $table->foreignId('kitchen_station_id')->nullable()
                ->constrained('kitchen_stations')->nullOnDelete();

            /* ------------------------------------------------- where it is */

            $table->string('host', 120)->nullable();

            // 9100 is the RAW/JetDirect port nearly every thermal printer
            // listens on, and nearly nobody changes it.
            $table->unsignedSmallInteger('port')->default(9100);

            /*
             | Characters per line, not millimetres.
             |
             | Paper is sold as 58mm and 80mm, but what the formatter needs is
             | how many characters fit, and that depends on the font as well
             | as the width. Storing the number the code actually uses means
             | nobody has to remember the conversion twice.
             */
            $table->unsignedTinyInteger('columns')->default(42);

            $table->unsignedTinyInteger('copies')->default(1);

            /*
             | Cut the paper after printing. Off for printers that have no
             | cutter, where the command prints as gibberish instead.
             */
            $table->boolean('auto_cut')->default(true);

            /* ------------------------------------------------- operational */

            // The one used when nothing more specific matches, per kind.
            $table->boolean('is_default')->default(false);

            $table->boolean('is_active')->default(true)->index();

            $table->string('notes', 190)->nullable();

            // Set by a successful print, so the list can say which printers
            // are actually being used and which have been silent for a week.
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'code']);
            $table->index(['shop_id', 'kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
