<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One sitting at one table.
     *
     * §3.7 puts an order against "the restaurant + outlet + table +
     * customer/session", and §3.11 wants later orders from the same table to
     * join the same bill. The session is the thing that makes both sentences
     * mean something: it is the party, not the furniture.
     *
     * Without it, "this table's order" can only mean "every order ever placed
     * at this table", and the guests who sat down at eight would inherit the
     * bill of the guests who left at seven.
     *
     * A session opens when somebody scans and there is no open one, and
     * closes when the bill is settled or staff clear the table. Exactly one
     * may be open per table at a time - held by TableSessionService's row
     * lock, because MySQL has no partial unique index to express it with.
     */
    public function up(): void
    {
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();

            /*
             | Which code was scanned to open it. Nullable because staff can
             | open a session from the POS for a table whose sticker has gone
             | missing, and restricted on delete so the audit trail survives -
             | "which code let these people in" is exactly the question a
             | disputed bill asks.
             */
            $table->foreignId('table_qr_id')->nullable()->constrained()->nullOnDelete();

            /*
             | The guest's own handle on the session, kept in their browser.
             |
             | Separate from the table's QR token and unguessable for a
             | different reason: the QR token is public by design - it is
             | printed on the table and anyone in the room may scan it - while
             | this identifies one party's cart and order history. Whoever
             | holds it can add to that bill.
             */
            $table->string('token', 64)->unique();

            /*
             | open    - guests seated, still ordering
             | billed  - bill raised, being settled
             | closed  - done, table released
             |
             | A string rather than an enum: see restaurant_tables.status.
             */
            $table->string('status', 20)->default('open');

            // What the guest told us, if anything. §14 allows ordering with
            // no login at all, so none of this is required.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name', 120)->nullable();
            $table->string('guest_mobile', 30)->nullable();
            $table->unsignedSmallInteger('covers')->nullable();

            /*
             | The bill this sitting became, once it has one. Nullable for the
             | whole time the party is still eating, which is most of a
             | session's life.
             */
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason', 250)->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['restaurant_table_id', 'status']);
            $table->index(['shop_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
    }
};
