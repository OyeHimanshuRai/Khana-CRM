<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The support desk between a restaurant and the platform (§2).
     *
     * ------------------------------------------------------------------
     * It hangs off the tenant, not the shop
     * ------------------------------------------------------------------
     *
     * §2 lists support tickets among the things Super Admin manages, beside
     * restaurants, plans and subscriptions - all of which are tenant-tier.
     * That is the right tier for it too: the person who raises a ticket about
     * a failed settlement is the owner of the business, not the evening
     * manager of one branch, and an answer that only reached one branch would
     * be the wrong answer.
     *
     * `shop_id` is kept and nullable because plenty of tickets *are* about one
     * branch - a printer at the rooftop counter, a QR sticker that stopped
     * scanning - and saying which one saves the first reply being "which
     * outlet?". It narrows a ticket; it never owns it.
     *
     * ------------------------------------------------------------------
     * Status is stored, and it is the one thing here that is
     * ------------------------------------------------------------------
     *
     * Elsewhere in this codebase a status that can be derived is derived - see
     * the subscription, whose state is read off `ends_at`. A ticket is the
     * opposite case. "Waiting on the customer" and "waiting on us" cannot be
     * computed from the reply timestamps without guessing at intent: a support
     * agent who replies with an answer and one who replies with a question
     * leave identical rows behind. So the agent says which, and the column
     * holds it.
     *
     * ------------------------------------------------------------------
     * The number is not the id
     * ------------------------------------------------------------------
     *
     * A reference a person reads out over the phone should not tell them how
     * many customers the platform has. It is generated per-ticket and unique
     * across the platform, so quoting it is enough to find one.
     */
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();

            /*
             | Who is asking. Not nullable: a ticket with no company behind it
             | has nobody to answer to and nobody to bill the time against.
             |
             | Deleting a company takes its tickets with it. The alternative -
             | orphaned threads nobody can open - is worse than losing the
             | history of a business that no longer exists.
             */
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // The branch it is about, when it is about one. See the docblock.
            $table->foreignId('shop_id')->nullable()
                ->constrained()->nullOnDelete();

            /*
             | Quoted over the phone, printed in mail subjects. Unique across
             | the platform so it is enough on its own - see the docblock for
             | why it is not the id.
             */
            $table->string('reference', 20)->unique();

            $table->string('subject', 180);

            /*
             | The opening message. Kept on the ticket rather than as the first
             | row of the replies table: every ticket has exactly one and it is
             | never a reply to anything, so modelling it as one would mean
             | every read of a list joined a table to find it.
             */
            $table->text('body');

            /*
             | What it is about. Free-form would be honest but useless - the
             | reason to ask at all is so the platform can see that eleven
             | restaurants filed a printer ticket this week.
             */
            $table->string('category', 30)->default('other');

            /*
             | How much it hurts. Set by whoever opens it and adjustable by the
             | desk, because a customer calling everything urgent is a fact of
             | support rather than a data problem.
             */
            $table->string('priority', 20)->default('normal');

            $table->string('status', 20)->default('open');

            /*
             | Who opened it. Nullable on delete rather than cascade: a ticket
             | outlives the staff member who raised it, and the thread is the
             | record of what was agreed.
             */
            $table->foreignId('opened_by')->nullable()
                ->constrained('users')->nullOnDelete();

            // The platform-side owner. Null means nobody has picked it up.
            $table->foreignId('assigned_to')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             | Denormalised from the replies table, and worth it: every list
             | screen sorts by it, and the alternative is a correlated subquery
             | on the busiest query in the module.
             */
            $table->timestamp('last_reply_at')->nullable();

            /*
             | First response time, which is the number a support desk is
             | actually judged on. Stored at the moment it happens because it
             | cannot be recovered later - a thread with nine replies no longer
             | remembers which was the first from the desk.
             */
            $table->timestamp('first_responded_at')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The desk's own queue: open work, newest movement first.
            $table->index(['status', 'last_reply_at']);

            // A restaurant's own list.
            $table->index(['tenant_id', 'status']);

            $table->index('assigned_to');
        });

        Schema::create('support_ticket_replies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();

            /*
             | Nullable because a reply can outlive its author's account, and a
             | thread missing every message from a departed agent is not a
             | thread. `author_name` below is why that is survivable.
             */
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             | Copied at write time. The name on a two-year-old reply should be
             | the name that wrote it, not the current name of whoever holds
             | that user row now - and it has to survive the row going away.
             */
            $table->string('author_name', 120);

            /*
             | Which side of the desk this came from. Derived from the author's
             | rights at the time and then frozen, because rights change: a
             | manager promoted to the platform team must not retroactively
             | turn their old customer-side messages into staff ones.
             */
            $table->boolean('from_staff')->default(false);

            $table->text('body');

            /*
             | A note the customer never sees. Same table as the conversation
             | on purpose - an internal note belongs in sequence with the
             | messages it is about, and a separate table would leave the desk
             | reading two lists interleaved by hand.
             |
             | Everything that renders a thread for a restaurant MUST filter
             | this out. See SupportTicket::visibleReplies().
             */
            $table->boolean('internal')->default(false);

            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_replies');
        Schema::dropIfExists('support_tickets');
    }
};
