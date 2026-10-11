<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Take the shelf off the bill.
     *
     * A sale in CRM used to say which warehouse it came out of and which
     * lot, because CRM counted stock and every sale moved it. It no longer
     * does - see the architecture doc's "CRM alone" table - so these columns
     * describe a movement that never happens. Worse, `sales_returns.warehouse_id`
     * is NOT NULL, so a return could not be recorded at all without naming a
     * warehouse CRM has no business knowing about.
     *
     * Which store a dish's ingredients come out of is ERP's question, and it
     * answers it from the outlet named on the crm.sale.* and crm.kitchen.*
     * events, not from anything written here.
     *
     *   invoices            warehouse_id
     *   invoice_items       batch_id, batch_no, expiry_date
     *   sales_returns       warehouse_id
     *   sales_return_items  batch_id, batch_no
     *   orders              warehouse_id
     *   order_items         reserved_batches
     *
     * Guarded column by column, so a database that is already partway there
     * is not an error.
     */
    public function up(): void
    {
        $this->dropForeign('invoices', 'warehouse_id');
        $this->dropForeign('invoice_items', 'batch_id');
        $this->dropColumns('invoice_items', ['batch_no', 'expiry_date']);

        $this->dropForeign('sales_returns', 'warehouse_id');
        $this->dropForeign('sales_return_items', 'batch_id');
        $this->dropColumns('sales_return_items', ['batch_no']);

        $this->dropForeign('orders', 'warehouse_id');
        $this->dropColumns('order_items', ['reserved_batches']);
    }

    public function down(): void
    {
        // Nothing to restore: no code reads or writes these any more.
    }

    private function dropForeign(string $table, string $column): void
    {
        if (Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $table) => $table->dropConstrainedForeignId($column));
        }
    }

    /** @param  list<string>  $columns */
    private function dropColumns(string $table, array $columns): void
    {
        $present = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));

        if ($present !== []) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
