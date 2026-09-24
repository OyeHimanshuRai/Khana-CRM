<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much of a received line has since gone back to the supplier.
     *
     * The same field `invoice_items.returned_quantity` already plays for a
     * sale: kept on the line rather than recomputed from the returns, so
     * "how much of this is left to send back" is one read, not a query
     * across every purchase return ever raised against it.
     */
    public function up(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->decimal('returned_quantity', 15, 3)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });
    }
};
