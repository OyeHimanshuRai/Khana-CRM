<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-app notifications (SRS 15, SRS 16's `notifications`).
     *
     * Named `alerts` rather than `notifications` because Laravel reserves the
     * latter for its own `DatabaseNotification` table, whose shape - a uuid key,
     * an untyped JSON payload, a polymorphic notifiable and nothing else - is
     * wrong for what the SRS asks. The eight notifications in SRS 15 are
     * branch-scoped operational events that need to be filtered by shop, grouped
     * by kind, deduplicated across a scheduler run and shown as a count in the
     * header. None of that is reachable inside an opaque JSON column, and
     * colliding with Laravel's table would mean neither could be used properly.
     *
     * Email is not this table. Mail is already handled by EmailLog, the
     * templates and the campaign engine; an alert is the bell in the admin
     * header. The two are raised together by the same event and are recorded
     * separately, so a mail outage cannot lose the in-app record.
     *
     * Idempotent by design, following the reminder engine's lesson
     * (docs/ERP-OVERVIEW section 6): the scheduler that raises these relies on
     * `dedupe_key` and a unique index rather than a "have I done this" query.
     * Running the scheduler twice, or resuming after a crash halfway through,
     * cannot produce two alerts about the same open till.
     */
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            /*
            | Who it is for.
            |
            | Null means "anyone at this branch who holds the permission below"
            | - which is how SRS 15 actually reads. "Low stock -> Branch Admin /
            | Warehouse" is a role, not a person, and addressing it to whichever
            | individual happened to be on shift would hide it from the person
            | who came in next.
            */
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            /*
            | The permission that gates an unaddressed alert. A cashier does not
            | need to see that a till is still open, and the header count has to
            | agree with what the list will actually show.
            */
            $table->string('can', 80)->nullable();

            /*
            | low_stock | payment_received | invoice_generated |
            | day_close_pending | security
            |
            | One key per row of SRS 15's table.
            */
            $table->string('type', 40)->index();

            // info | success | warning | danger - drives the pill colour, using
            // the same tones as the permission matrix.
            $table->string('level', 20)->default('info');

            $table->string('title', 190);
            $table->string('body', 500)->nullable();

            // Where clicking it goes. A relative path rather than a route name
            // and parameters, because an alert has to survive a route being
            // renamed without throwing on render.
            $table->string('link', 255)->nullable();

            // What it is about: a CashRegister, an Invoice, a Product.
            $table->nullableMorphs('reference');

            /*
            | What makes this alert *this* alert - typically type + reference +
            | the day. The scheduler writes through it, so a second run updates
            | rather than duplicates. See the class note.
            */
            $table->string('dedupe_key', 190);

            $table->timestamp('read_at')->nullable();

            // Alerts are transient by nature; this is what the pruner deletes
            // on. Null for one that should stay until it is dealt with.
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['shop_id', 'dedupe_key']);

            // The bell: unread, newest first, for this branch.
            $table->index(['shop_id', 'read_at', 'created_at']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
