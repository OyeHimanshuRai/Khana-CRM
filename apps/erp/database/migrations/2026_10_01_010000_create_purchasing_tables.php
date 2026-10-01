<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buying: who from, what was ordered, what arrived, what is owed and what
 * has been paid.
 *
 * Suppliers belong to the business - a chain buys from the same dairy at
 * every outlet. Everything that happens with them happens at one outlet.
 * Quantities and cost rates are the whole numbers the stock tables use;
 * money is whole paise.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* The next number for each kind of document, per outlet. */
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->char('outlet_id', 26);
            $table->string('key', 20);
            $table->unsignedBigInteger('value')->default(0);

            $table->unique(['outlet_id', 'key']);
        });

        Schema::table('outlet_settings', function (Blueprint $table) {
            // A restaurant charging 5% GST cannot claim the tax on what it
            // buys, so that tax is part of what the paneer cost. An outlet
            // that does claim it turns this off.
            $table->boolean('input_tax_is_cost')->default(true);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('address')->nullable();
            // Days after the bill date that payment falls due.
            $table->unsignedSmallInteger('terms_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->foreignId('supplier_id')->constrained();
            $table->string('number', 30);
            $table->date('ordered_on');
            $table->date('expected_on')->nullable();
            // open | part_received | received | cancelled
            $table->string('status', 16)->default('open');
            $table->bigInteger('total_paise')->default(0);
            $table->string('notes', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'number']);
            $table->index(['outlet_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained();
            $table->bigInteger('qty');
            // As typed, so the order prints the way it was written.
            $table->string('entered_qty', 20);
            $table->string('entered_unit', 20);
            $table->bigInteger('cost_rate')->default(0);
            $table->bigInteger('amount_paise')->default(0);
            $table->bigInteger('received_qty')->default(0);
            $table->timestamps();
        });

        /*
        | What is owed to a supplier for one delivery. Carries a ULID because
        | CRM names it: the cashier's paid-out screen lists open bills, and
        | the paid-out event says which one the cash was for.
        */
        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->foreignId('supplier_id')->constrained();
            $table->string('bill_no', 60);
            $table->date('billed_on');
            $table->date('due_on')->nullable();
            $table->bigInteger('total_paise');
            $table->bigInteger('paid_paise')->default(0);
            // open | paid | cancelled
            $table->string('status', 12)->default('open');
            $table->string('notes', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'status', 'due_on']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->foreignId('store_id')->constrained();
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->foreignId('purchase_order_id')->nullable()->constrained();
            $table->foreignId('supplier_bill_id')->nullable()->constrained();
            $table->string('number', 30);
            $table->date('received_on');
            $table->string('bill_no', 60)->nullable();
            $table->bigInteger('net_paise')->default(0);
            $table->bigInteger('tax_paise')->default(0);
            $table->bigInteger('total_paise')->default(0);
            $table->string('notes', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'number']);
            $table->index(['outlet_id', 'received_on']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained();
            $table->unsignedBigInteger('batch_id')->default(0);
            $table->bigInteger('qty');
            $table->string('entered_qty', 20);
            $table->string('entered_unit', 20);
            // What went into stock: net, plus the tax when it is a cost.
            $table->bigInteger('cost_rate')->default(0);
            $table->bigInteger('net_paise')->default(0);
            $table->bigInteger('tax_paise')->default(0);
            $table->timestamps();
        });

        /*
        | Money to a supplier, or a credit from one (goods sent back). Tied to
        | a bill when it settles one; an advance is tied to none.
        */
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('supplier_bill_id')->nullable()->constrained();
            // payment | credit
            $table->string('kind', 10)->default('payment');
            $table->bigInteger('amount_paise');
            // cash | bank | upi | cheque | drawer | return
            $table->string('method', 12);
            $table->date('paid_on');
            $table->string('reference', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'paid_on']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->string('category', 40);
            $table->bigInteger('amount_paise');
            $table->date('spent_on');
            // cash | bank | upi | drawer
            $table->string('method', 12)->default('cash');
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'spent_on']);
        });

        /*
        | Cash that left CRM's drawer (crm.cash.paid_out). It waits here until
        | somebody says what it was - a payment to a supplier, or an expense -
        | unless the cashier already named the bill it paid.
        */
        Schema::create('paid_outs', function (Blueprint $table) {
            $table->id();
            // CRM's id for it.
            $table->char('ulid', 26)->unique();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->date('business_date');
            $table->bigInteger('amount_paise');
            $table->string('category', 40);
            $table->string('note', 1000)->nullable();
            // pending | filed
            $table->string('status', 10)->default('pending');
            $table->foreignId('supplier_payment_id')->nullable()->constrained();
            $table->foreignId('expense_id')->nullable()->constrained();
            $table->timestamps();

            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach ([
            'paid_outs', 'expenses', 'supplier_payments', 'goods_receipt_lines', 'goods_receipts',
            'supplier_bills', 'purchase_order_lines', 'purchase_orders', 'suppliers', 'counters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('outlet_settings', fn (Blueprint $table) => $table->dropColumn('input_tax_is_cost'));
    }
};
