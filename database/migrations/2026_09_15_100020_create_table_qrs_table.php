<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per QR code ever issued for a table.
     *
     * A row rather than a column on `restaurant_tables`, because §7 asks to
     * "regenerate/deactivate QR when required" and the interesting half of
     * that is what happens to the old one. Overwriting a token would leave
     * every printed sticker silently resolving to the new code; a revoked row
     * lets the scan land on "this code is no longer in use, please ask staff"
     * instead, which is the difference between a customer who is confused for
     * five seconds and one who orders against the wrong table.
     *
     * Exactly one active code per table at a time. That is not enforced by a
     * plain unique index - several revoked rows share the same table_id - so
     * it is a partial index where the driver has them and TableQrService's
     * transaction where it does not. MySQL has no partial indexes, hence the
     * service; the index below still makes the lookup cheap.
     */
    public function up(): void
    {
        Schema::create('table_qrs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();

            /*
             | What the QR actually encodes, as the last path segment of the
             | public menu URL. 32 URL-safe characters: long enough that
             | guessing another table's code is not worth anybody's afternoon,
             | short enough that the QR stays readable printed at 25mm on a
             | thermal sticker.
             |
             | Globally unique, not per shop. The public route resolves a
             | token with no tenant in context - there is nobody signed in at
             | that point - so the token has to identify the branch by itself.
             */
            $table->string('token', 64)->unique();

            /*
             | Revoked rather than deleted, and the reason is kept: an
             | operator asking "why did table 7's code change last Tuesday"
             | deserves an answer.
             */
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 250)->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             | How many times it has been scanned, and when it last was. Not
             | an analytics feature: it is what tells an operator that the
             | sticker they reprinted is the one on the table, before they go
             | and look.
             */
            $table->unsignedInteger('scan_count')->default(0);
            $table->timestamp('last_scanned_at')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'revoked_at']);
            $table->index(['restaurant_table_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_qrs');
    }
};
