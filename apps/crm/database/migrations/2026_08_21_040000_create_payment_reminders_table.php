<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment reminders: both the schedule and the log.
     *
     * One table for both because they are the same row at two moments -
     * planned, then sent. Splitting them would mean reconciling two tables
     * to answer "did this customer actually get told", which is the only
     * question anyone asks of a reminder system.
     *
     * The unique index is the important part: it is what stops a customer
     * being emailed the same reminder twice because the scheduler ran twice,
     * which is the failure mode that gets a shop's mail marked as spam.
     */
    public function up(): void
    {
        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->cascadeOnDelete();

            // upcoming | due_today | overdue | long_overdue | payment_received
            $table->string('trigger', 30)->index();

            // email | sms | whatsapp | in_app
            $table->string('channel', 20)->default('email')->index();

            // pending | sent | failed | skipped | cancelled
            $table->string('status', 20)->default('pending')->index();

            $table->timestamp('scheduled_for');
            $table->timestamp('sent_at')->nullable();

            /*
             | Who it went to, captured at scheduling time. A customer who
             | changes their number later should not make the log lie about
             | where the message was actually sent.
             */
            $table->string('recipient', 190)->nullable();
            $table->string('subject', 250)->nullable();
            $table->text('body')->nullable();

            // What was owed when the reminder was raised. The invoice may
            // have been paid since; the reminder still said what it said.
            $table->decimal('amount_due', 15, 2)->default(0);
            $table->date('due_date')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            // Set when a reminder is deliberately not sent - the invoice was
            // paid before the run, the customer has no address, the shop
            // turned that trigger off.
            $table->string('skip_reason', 190)->nullable();

            $table->timestamps();

            /*
             | One reminder per invoice per trigger per channel, ever. The
             | scheduler is idempotent because of this line, which means it
             | can be run as often as anyone likes without consequence.
             */
            $table->unique(['invoice_id', 'trigger', 'channel'], 'payment_reminders_unique_send');

            $table->index(['status', 'scheduled_for']);
            $table->index(['shop_id', 'status', 'scheduled_for']);
            $table->index(['customer_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminders');
    }
};
