<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make `orders` carry a restaurant order as well as a web one (§3.7).
     *
     * ----------------------------------------------------------------------
     * Why one table and not `table_orders`
     * ----------------------------------------------------------------------
     *
     * §13 wants sales reported across channels and §6 wants the POS to handle
     * "table order, takeaway, delivery and counter sale" from one screen. Two
     * order tables would mean every one of those is a UNION somebody has to
     * remember to write, and the first report that forgets is wrong in a way
     * nobody notices for a month.
     *
     * The storefront's order already *is* a channel of this business - §2
     * calls it "Online Orders". What changes here is that two of its columns
     * stop being universal truths:
     *
     *   customer_id     a dine-in guest need not sign in (§14)
     *   payment_method  a table pays at the end, not when it orders
     *
     * Both are widened to nullable rather than defaulted, because "no
     * customer" and "customer unknown" are the same thing here and a
     * placeholder row would pollute the CRM.
     *
     * ----------------------------------------------------------------------
     * One status ladder, not two
     * ----------------------------------------------------------------------
     *
     * The existing web statuses and the kitchen's are the same shape once you
     * stop calling the middle one "packing":
     *
     *   placed -> accepted -> preparing -> ready -> served | delivered
     *
     * So the column is left alone and the *vocabulary* moves in
     * App\Models\Order. Existing rows keep their words and the model maps
     * them; see Order::STATUSES. Rewriting live rows to new spellings would
     * break every report that has already been run and filed.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            /*
             | The sitting this order belongs to. Null for a web order, which
             | has no table - and the reason this is nullable rather than a
             | separate table. Restricted on delete: a session is never
             | deleted (it is the history behind a bill), so this should never
             | fire, and if it somehow does it must fail loudly.
             */
            $table->foreignId('table_session_id')
                ->nullable()
                ->after('customer_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             | dine_in | takeaway | delivery | online
             |
             | Defaulted to `online` so every row that already exists keeps
             | meaning exactly what it meant: those came from the storefront.
             */
            $table->string('order_type', 20)->default('online')->after('order_number');

            /*
             | Who ordered, when nobody signed in. §14 allows guest ordering,
             | and a bill still has to be able to say whose it was.
             */
            $table->string('guest_name', 120)->nullable()->after('table_session_id');
            $table->string('guest_mobile', 30)->nullable()->after('guest_name');

            /*
             | The kitchen's clock. Separate columns rather than reading the
             | status log, because §9 wants a preparation-time report and
             | "when did this ticket turn ready" should be one column, not a
             | scan of an audit table.
             */
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();

            $table->index(['shop_id', 'order_type', 'status']);
            $table->index(['table_session_id', 'id']);
        });

        /*
         | A dine-in guest need not sign in, and a table pays at the end.
         |
         | Widening only, so every row that already exists stays valid.
         | Laravel 11 dropped the doctrine/dbal requirement, so `change()`
         | works natively on MySQL, Postgres and the SQLite the tests run on.
         |
         | The whole definition is restated, not just the nullability -
         | `change()` rewrites the column from what it is given, so anything
         | left out is dropped. The foreign keys are untouched by this and are
         | not re-declared.
         */
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->string('payment_method', 20)->nullable()->change();

            /*
             | And the delivery address, for the same reason.
             |
             | These three were NOT NULL because every order this table had
             | ever held was a web order being shipped somewhere. A table in a
             | dining room is not being shipped anywhere, and a placeholder
             | like "-" would show up on a picking list as an address.
             |
             | Still required for a delivery order - OrderService validates
             | them at checkout, which is where that rule belongs. The column
             | was never the right place to enforce a rule that only applies
             | to one of four channels.
             */
            $table->string('ship_recipient_name', 150)->nullable()->change();
            $table->string('ship_mobile', 20)->nullable()->change();
            $table->string('ship_address_line1', 190)->nullable()->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            /*
             | The size ordered, and its name copied alongside.
             |
             | Copied for the same reason an invoice line copies its tax rate:
             | the menu gets re-priced and re-worded, and a bill raised last
             | Tuesday has to keep saying what it said. The id is kept too,
             | so a report can still group "every Full biryani".
             */
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained()
                ->nullOnDelete();

            $table->string('variant_name', 60)->nullable()->after('product_name');

            // §3.5: what the guest asked for. Printed on the kitchen ticket.
            $table->string('note', 250)->nullable();
        });

        /*
         | The add-ons on one order line (§16).
         |
         | A table rather than a JSON column on the line, because §13 asks for
         | item-wise sales and "how many extra cheeses did we sell in March" is
         | exactly the question a restaurant asks of its add-ons. That is a
         | GROUP BY, not a JSON scan.
         */
        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();

            /*
             | Nullable, and the names beside it are why: an option deleted
             | from the menu next season must not take the record of what was
             | eaten with it. The id is for grouping while it exists; the
             | names are what the bill reprints.
             */
            $table->foreignId('modifier_option_id')->nullable()->constrained()->nullOnDelete();

            $table->string('modifier_name', 120);
            $table->string('option_name', 120);

            // What it added to the line, at the moment it was ordered.
            $table->decimal('price', 15, 4)->default(0);

            $table->timestamps();

            $table->index(['order_item_id', 'id']);
            $table->index('modifier_option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_modifiers');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn(['variant_name', 'note']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'order_type', 'status']);
            $table->dropIndex(['table_session_id', 'id']);
            $table->dropConstrainedForeignId('table_session_id');
            $table->dropColumn([
                'order_type', 'guest_name', 'guest_mobile',
                'accepted_at', 'ready_at', 'served_at',
            ]);
        });

        // customer_id and payment_method are left nullable. Narrowing them
        // again would fail on any dine-in row already written.
    }
};
