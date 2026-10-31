<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money the shop spends that is not buying stock.
     *
     * Rent, electricity, wages, the van's diesel. Kept apart from goods
     * receipts on purpose: those buy something that goes on a shelf and can
     * be sold again, and mixing the two would make both the stock valuation
     * and the profit figure wrong.
     *
     * Categories are rows rather than an enum because every shop's list is
     * different and adding one should not need a migration.
     */
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->nullable()->constrained()->cascadeOnDelete()
                ->comment('Null means available to every shop');

            $table->string('name', 120);
            $table->string('description', 250)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('reference', 60);
            $table->date('spent_on');

            $table->string('title', 190);
            $table->text('notes')->nullable();

            // Who was paid. Free text rather than a supplier link: most of
            // these are people the shop will never buy stock from.
            $table->string('paid_to', 190)->nullable();

            $table->decimal('amount', 15, 2);

            // cash | upi | card | bank | cheque
            $table->string('method', 20)->default('cash');
            $table->string('transaction_ref', 120)->nullable();

            // draft | approved | rejected
            // Approving is what posts the payment, so an expense somebody
            // typed in is not money out until it is agreed.
            $table->string('status', 20)->default('draft')->index();

            // Photograph of the bill. The SRS asks for an attachment, and
            // without one an expense is somebody's word.
            $table->string('attachment_path')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name', 120)->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->string('review_note', 250)->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'reference']);
            $table->index(['shop_id', 'spent_on']);
            $table->index(['shop_id', 'status', 'spent_on']);
            $table->index(['expense_category_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
