<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GST slabs.
     *
     * Stored as one total rate plus the intra-state split, rather than
     * derived at print time: slabs change, and an invoice raised last year
     * has to keep reprinting with the rate it was actually taxed at. Line
     * items therefore copy the numbers rather than pointing at this row.
     */
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();

            $table->string('name', 60);

            // Total percentage, e.g. 18.00.
            $table->decimal('rate', 6, 3)->default(0);

            // Intra-state halves. Kept explicitly because a few slabs do not
            // split evenly, and because the invoice has to print both lines.
            $table->decimal('cgst', 6, 3)->default(0);
            $table->decimal('sgst', 6, 3)->default(0);

            // Inter-state equivalent; normally equal to `rate`.
            $table->decimal('igst', 6, 3)->default(0);

            $table->decimal('cess', 6, 3)->default(0);

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['sort_order', 'rate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
