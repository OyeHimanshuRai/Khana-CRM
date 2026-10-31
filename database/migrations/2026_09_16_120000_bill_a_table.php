<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing a sitting, and splitting the bill (§6).
     *
     * ---------------------------------------------------------------------
     * One sitting, several bills
     * ---------------------------------------------------------------------
     *
     * §6 wants a bill split "by item, quantity or amount". Only the last of
     * those is one invoice; the other two are two invoices off one table, and
     * that is a one-to-many the other way round from the column that was here
     * before.
     *
     * So the link moves onto `invoices`. "Which bills came off table four
     * tonight" is then one indexed read, and "what is still to bill" is
     * `settled_quantity < quantity` on the lines.
     *
     * `table_sessions.invoice_id` goes with it. Nothing ever read it - it was
     * reserved for this and is now the wrong shape - and a column that looks
     * authoritative while being unable to represent a split bill is a trap for
     * whoever reaches for it next.
     *
     * ---------------------------------------------------------------------
     * Why a quantity rather than a flag
     * ---------------------------------------------------------------------
     *
     * "Three of the five beers are mine" is a real split and a boolean cannot
     * say it. A decimal can, and it degrades correctly: a line is fully billed
     * when `settled_quantity` reaches `quantity`, whether that took one bill or
     * three.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('settled_quantity', 12, 3)
                ->default(0)
                ->after('quantity');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('table_session_id')
                ->nullable()
                ->after('customer_id')
                /*
                 | nullOnDelete, not cascade. Deleting a sitting must never
                 | take a tax invoice with it - that document has been filed,
                 | and it is the shop's, not the table's.
                 */
                ->constrained('table_sessions')
                ->nullOnDelete();

            $table->index(['table_session_id', 'invoiced_at']);
        });

        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['table_session_id', 'invoiced_at']);
            $table->dropConstrainedForeignId('table_session_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('settled_quantity');
        });
    }
};
