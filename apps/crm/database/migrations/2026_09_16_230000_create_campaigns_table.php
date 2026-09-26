<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sending the same message to a lot of people (SRS 15, 21).
     *
     * ------------------------------------------------------------------
     * A recipient row per person, written before anything is sent
     * ------------------------------------------------------------------
     *
     * The obvious design resolves the audience at send time and loops. It is
     * wrong in three ways that all show up on the first campaign that matters:
     *
     *   - a campaign interrupted half way has no record of who already got
     *     it, so resuming sends twice to some and never to others;
     *   - "why did Mrs Mehta get this" has no answer;
     *   - a segment that shifts while the send is running - somebody visits,
     *     somebody's points expire - silently changes the audience mid-flight.
     *
     * So the audience is frozen into rows first, and sending walks the rows.
     * The cost is a table that grows; the benefit is that a campaign is a
     * thing that happened rather than a thing that was attempted.
     *
     * ------------------------------------------------------------------
     * Consent is not modelled here, and that is deliberate
     * ------------------------------------------------------------------
     *
     * India's TRAI rules and WhatsApp's own policy both require opt-in, and
     * both are enforced by the provider, not by this table. A system that
     * kept its own consent flag would let a restaurant believe it had
     * permission the provider will refuse to act on. What this does instead
     * is refuse to send to anybody with no mobile number, and leave the rest
     * to the gateway - see CampaignService.
     */
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);

            // sms | whatsapp
            $table->string('channel', 20)->default('sms');

            /*
             | The message. For WhatsApp this is the fallback body and the
             | template below is what actually sends - see config/whatsapp.php
             | for why a template is not optional there.
             */
            $table->text('body');
            $table->string('template', 120)->nullable();

            /*
             | Who it goes to, as rules rather than a list of ids.
             |
             | Stored so the screen can show what was asked for months later.
             | The ids it resolved to are in campaign_recipients, because the
             | rules and their answer are different facts and a campaign needs
             | both: one to explain the intent, one to prove what happened.
             */
            $table->json('segment')->nullable();

            // draft | scheduled | sending | sent | cancelled
            $table->string('status', 20)->default('draft')->index();

            $table->dateTime('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            /*
             | Tallies, kept on the row rather than counted on render. The
             | list screen shows every campaign at once and counting three
             | ways per row would be a query storm on the one page a manager
             | opens most.
             */
            $table->unsignedInteger('audience_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            // The sender's query: due, and not already going out.
            $table->index(['status', 'scheduled_for']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            /*
             | Frozen at the moment the audience was built. A customer who
             | changes their number afterwards does not silently redirect a
             | message that was already addressed.
             */
            $table->string('destination', 30);
            $table->string('name', 120)->nullable();

            // pending | sent | failed | skipped
            $table->string('status', 20)->default('pending')->index();

            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // One message per person per campaign, whatever happens to the
            // sender half way through.
            $table->unique(['campaign_id', 'destination']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
