<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash handed out of the till for something that never became a Payment
     * row - an auto fare, a delivery boy's tip, a few rupees for milk.
     *
     * This is deliberately not the old Expense: no vendor, no category, no
     * GST, no approval queue - that whole ledger of what the business spent
     * money on belongs to ERP now. This table only answers the till's own
     * question at day close: how much cash left the drawer today that
     * Payment does not already account for. See CashRegisterService.
     */
    public function up(): void
    {
        Schema::create('cash_paid_outs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('reason', 190);
            $table->timestamp('paid_at');

            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('paid_by_name', 120)->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_paid_outs');
    }
};
