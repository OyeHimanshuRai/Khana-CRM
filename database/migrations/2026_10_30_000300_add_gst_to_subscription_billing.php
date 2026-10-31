<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| GST on what a restaurant pays for its plan (config payments.invoice.gst_rate).
|
| A billing order keeps the taxable value, the rate and the tax beside the
| amount charged, so the receipt can itemise them exactly as they were. A
| payment keeps its tax, so the credit for unused time is worked out from
| what was paid before tax and tax is never credited back twice.
|
| Rows from before have no tax recorded (0), which is what they charged.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_orders', function (Blueprint $table) {
            $table->decimal('taxable_amount', 12, 2)->nullable()->after('credit');
            $table->decimal('gst_rate', 5, 2)->default(0)->after('taxable_amount');
            $table->decimal('gst_amount', 12, 2)->default(0)->after('gst_rate');
        });

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->decimal('tax_amount', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('billing_orders', function (Blueprint $table) {
            $table->dropColumn(['taxable_amount', 'gst_rate', 'gst_amount']);
        });

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
        });
    }
};
