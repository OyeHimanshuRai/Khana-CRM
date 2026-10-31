<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every step an order took, and how long it sat there (SRS 16).
     *
     * ------------------------------------------------------------------
     * Why this is not just ActivityLog
     * ------------------------------------------------------------------
     *
     * ActivityLog already records that somebody changed an order's status. It
     * is the right place for that: it is one table for every audited action,
     * searchable by person, and nobody has to learn a new one.
     *
     * What it cannot answer is "how long did this order sit in Preparing",
     * because its payload is a sentence. Getting a duration out of it means
     * parsing prose, and getting an average across a service means parsing
     * prose ten thousand times.
     *
     * So this table stores the two statuses and the seconds between them as
     * columns. That is the whole difference, and it is the difference between
     * a report that runs and a report nobody writes.
     *
     * ------------------------------------------------------------------
     * Written by the model, not by callers
     * ------------------------------------------------------------------
     *
     * An observer on Order catches every path - the POS, the kitchen screen,
     * the guest journey, a console command, a future one nobody has written.
     * A log that depends on each caller remembering to write to it is a log
     * with holes exactly where somebody was in a hurry.
     */
    public function up(): void
    {
        Schema::create('order_status_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /*
             | Null on the first row, which is the order being created. A
             | sentinel like 'none' would have to be excluded from every
             | query that groups by status; null already is.
             */
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);

            /*
             | How long it sat in `from_status`. The reason this table exists.
             |
             | Stored rather than derived from the gap between rows, because
             | deriving it means a window function or a self-join, and both
             | get the first and last rows of an order wrong in ways nobody
             | notices until a report is presented to somebody.
             */
            $table->unsignedInteger('seconds_in_previous')->nullable();

            /*
             | Null for anything the system did to itself - a scheduled
             | sweep, a webhook, a guest's own action. Not every status change
             | has a person behind it, and inventing one would make the staff
             | report a work of fiction.
             */
            $table->foreignId('changed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('note', 190)->nullable();

            $table->timestamps();

            $table->index(['order_id', 'id']);
            // The report's query: every step of a kind, over a period.
            $table->index(['shop_id', 'to_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_logs');
    }
};
