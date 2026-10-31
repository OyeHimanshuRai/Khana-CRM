<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which "your plan is ending" emails a subscription has already had.
     *
     * The unique index is the whole point, the same idea as payment_reminders'
     * (invoice_id, trigger, channel): the command inserts first and sends only
     * if the insert landed, so a second run - or a cron that fires twice, or a
     * run resumed after a crash - cannot send the same email twice. A "have I
     * sent this" query would race with itself; an index cannot.
     *
     * `ends_on` is in the key so that renewing starts the countdown again. The
     * notice for "ends on 1 Nov" is not the notice for "ends on 1 Dec", and a
     * business that renews and then lets the new term run out must hear about
     * that one too.
     */
    public function up(): void
    {
        Schema::create('subscription_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            // ending_2 / ending_1 / ending_0 / ended - see SubscriptionNoticeService.
            $table->string('kind', 20);
            $table->date('ends_on');
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'kind', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_notices');
    }
};
