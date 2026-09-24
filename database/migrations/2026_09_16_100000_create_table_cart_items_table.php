<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a table has picked but not yet sent to the kitchen (§3.5, §3.6).
     *
     * ----------------------------------------------------------------------
     * Why not `carts`
     * ----------------------------------------------------------------------
     *
     * The storefront's `carts` belongs to a customer and lives for weeks -
     * it is half a wishlist. This belongs to a *sitting*, lives for one meal,
     * and carries two things the storefront cart has no concept of: a chosen
     * size and a set of chosen add-ons. Widening `carts` to hold both would
     * make every storefront query step over columns it never uses, and every
     * "whose cart is this" answer would have two possible shapes.
     *
     * One table rather than three: the items hang straight off the session,
     * because a cart with no items is not a thing worth a row.
     *
     * ----------------------------------------------------------------------
     * Why the cart is shared by every phone at the table
     * ----------------------------------------------------------------------
     *
     * §3.11 says later orders from a table join the same bill, and the
     * session is already shared by everyone who scanned that sticker. A cart
     * per device would mean four phones quietly building four orders that all
     * land on one bill anyway - with nobody able to see what the others had
     * added, and the same dish ordered twice.
     *
     * The cost is that an item appearing on your screen may have been added
     * by the person opposite. For one table sharing one bill that is the
     * behaviour people expect of a paper pad.
     */
    public function up(): void
    {
        Schema::create('table_cart_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            /*
             | The sitting, not the table. A cart that outlived the party
             | would hand the next guests the last one's half-finished order.
             */
            $table->foreignId('table_session_id')->constrained()->cascadeOnDelete();

            /*
             | Restricted rather than cascaded: deleting a dish that is in
             | somebody's cart right now should be refused by the controller,
             | not silently empty their basket between taps.
             */
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            /*
             | The size chosen, where the dish has sizes. Required in practice
             | for those dishes and enforced in the service - a null here on a
             | dish with sizes is how a table gets billed for a Full and
             | served a Half - but nullable in the column, because most dishes
             | come one way.
             */
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('quantity')->default(1);

            // §3.5: "special instructions". Reaches the kitchen ticket.
            $table->string('note', 250)->nullable();

            /*
             | Which add-on options were ticked, as ids.
             |
             | Ids rather than a price snapshot, because a cart is not a
             | promise: it is re-priced from the live rows every time it is
             | rendered, so a kitchen that changes what cheese burst costs at
             | six does not owe a discount to somebody who opened the menu at
             | five. The snapshot happens when the order is placed, which is
             | the moment a price becomes a commitment.
             */
            $table->json('option_ids')->nullable();

            /*
             | Who put it there, so a guest can tell their own lines apart on
             | a shared cart. The session token of the device, not a person -
             | there is nobody signed in.
             */
            $table->string('added_by', 64)->nullable();

            $table->timestamps();

            $table->index(['table_session_id', 'id']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_cart_items');
    }
};
