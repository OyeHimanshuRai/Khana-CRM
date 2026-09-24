<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An online order - the checkout artifact, not the bill.
     *
     * This is deliberately a separate document from Invoice. An Invoice can
     * only be raised once there is something concrete to say was collected
     * (InvoiceService refuses a part-paid/credit sale to a customer who
     * isn't credit-eligible, and a storefront customer normally isn't), so
     * a COD order placed with nothing paid yet has nowhere to live until the
     * shop actually collects. An Order carries its own fulfilment status
     * (pending/confirmed/packing/shipped/delivered/cancelled) for exactly
     * that gap, and is linked to the Invoice once one exists.
     *
     * Stock is *reserved* (StockService::reserve, no ledger row) when the
     * order is placed, and only actually *issued* when the shop marks it
     * paid and OrderService raises the real Invoice via InvoiceService -
     * which is the one and only place stock is ever taken from the shelf.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            // Not nullable: checkout is login-only, there is no guest order.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Captured at placement and reused unchanged through fulfilment,
            // so a mid-flight change to the shop's default warehouse cannot
            // retarget stock already reserved against this order.
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            $table->string('order_number', 40);

            // pending | confirmed | packing | shipped | delivered | cancelled
            $table->string('status', 20)->default('pending')->index();

            // cod | online
            $table->string('payment_method', 20);

            // pending | paid | failed | refunded
            $table->string('payment_status', 20)->default('pending')->index();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);

            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();

            $table->decimal('shipping_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            /*
             | Shipping address, copied - not referenced - onto the order at
             | checkout. Same rule invoices.billing_address already follows:
             | a document has to keep printing what it said at the time, even
             | after the address book row is edited or removed.
             */
            $table->string('ship_recipient_name', 150);
            $table->string('ship_mobile', 20);
            $table->string('ship_address_line1', 190);
            $table->string('ship_address_line2', 190)->nullable();
            $table->string('ship_village', 120)->nullable();
            $table->string('ship_taluka', 120)->nullable();
            $table->string('ship_district', 120)->nullable();
            $table->string('ship_city', 90)->nullable();
            $table->string('ship_state', 90)->nullable();
            $table->string('ship_pincode', 12)->nullable();

            $table->text('customer_note')->nullable();

            // Set once the shop marks the order paid and the real bill is
            // raised - see OrderService::markPaid().
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->dateTime('placed_at');

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->timestamp('delivered_at')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 250)->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'order_number']);
            $table->index(['shop_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['shop_id', 'placed_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name', 190);
            $table->string('sku', 60)->nullable();

            $table->decimal('quantity', 15, 3);

            // Tax-inclusive counter price at order time - what the customer
            // actually saw and agreed to pay.
            $table->decimal('unit_price', 15, 4);
            $table->decimal('line_total', 15, 2);

            /*
             | The exact FEFO allocation reserved at placement:
             | [{batch_id, warehouse_id, quantity}, ...]. Recorded so the
             | reservation can be released precisely later - a fresh pick at
             | fulfilment time could legitimately choose a different batch
             | than the one actually held, which would release the wrong lot.
             */
            $table->json('reserved_batches')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
