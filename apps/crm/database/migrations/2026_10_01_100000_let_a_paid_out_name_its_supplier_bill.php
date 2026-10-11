<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which supplier bill a paid-out settled, when the cashier named one.
     *
     * The bill is ERP's row, not CRM's - purchasing moved there - so this is
     * its ULID and not a foreign key: there is no table here for one to
     * point at. crm.cash.paid_out carries it as supplier_bill_id, and ERP
     * turns the paid-out into a payment against that bill.
     *
     * The supplier's name and the bill number are kept beside it, as they
     * read on the screen the cashier picked from. The day close lists what
     * left the drawer, and it must be able to say "Fresh Farms, FF/102"
     * with ERP switched off - and go on saying it after ERP has renamed the
     * supplier, because that is what was paid on the day.
     *
     * All three are null for cash that paid no bill, which is most of it.
     */
    public function up(): void
    {
        Schema::table('cash_paid_outs', function (Blueprint $table) {
            $table->string('supplier_bill_ulid', 26)->nullable()->after('reason');
            $table->string('supplier_name', 190)->nullable()->after('supplier_bill_ulid');
            $table->string('supplier_bill_no', 100)->nullable()->after('supplier_name');
        });
    }

    public function down(): void
    {
        Schema::table('cash_paid_outs', function (Blueprint $table) {
            $table->dropColumn(['supplier_bill_ulid', 'supplier_name', 'supplier_bill_no']);
        });
    }
};
