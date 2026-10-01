<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the store holds, and every reason it changed.
 *
 * Three conventions run through every table ERP owns:
 *
 *   - `tenant_id` and `outlet_id` are Accounts' ULIDs, the keys of the
 *     reference copy (khana_tenants, khana_outlets). Rows have their own
 *     numeric ids; nothing here is keyed by a ULID of its own unless another
 *     service has to name it.
 *
 *   - A quantity is a whole number of ten-thousandths of the item's base unit
 *     (g, ml or piece): 250 g is 2500000. Whole numbers, so a level always
 *     equals the sum of its movements exactly - there is no float to drift.
 *
 *   - A cost rate is ten-thousandths of a paisa per base unit: paneer at
 *     Rs 400 a kilo is 40 paise a gram, 400000. Money itself is whole paise.
 *
 * App\Support\Units converts both ways; nothing else should do the sums.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | Where stock sits inside an outlet. Every outlet gets a main store
        | (where goods are received) and a kitchen store (where recipes are
        | consumed from) the first time ERP is opened for it.
        */
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->string('name');
            $table->string('code', 12);
            // main | kitchen | other
            $table->string('kind', 12)->default('other');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code']);
        });

        /* How stock behaves at one outlet. A missing row means the defaults. */
        Schema::create('outlet_settings', function (Blueprint $table) {
            $table->char('outlet_id', 26)->primary();
            $table->char('tenant_id', 26)->index();
            // The kitchen stage at which a dish's ingredients are booked:
            // accepted | ready | served.
            $table->string('consume_stage', 12)->default('ready');
            // Expired batches do not count towards "can we still make this".
            $table->boolean('exclude_expired')->default(true);
            $table->timestamps();
        });

        /*
        | What the store buys, holds and cooks with. Shared by every outlet of
        | a business; the levels are per store.
        */
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->string('name');
            $table->string('code', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('category', 100)->nullable();
            // g | ml | pc
            $table->string('base_unit', 4);
            // Made in-house from other stock items (a gravy base).
            $table->boolean('is_prepared')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->string('hsn', 12)->nullable();
            // Input GST in basis points: 500 is 5%.
            $table->unsignedSmallInteger('gst_rate_bp')->default(0);
            // Below this much on hand, the item is on the low-stock list.
            $table->bigInteger('reorder_qty')->default(0);
            // What it last cost, for valuing an item with nothing on hand.
            $table->bigInteger('last_cost_rate')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'barcode']);
        });

        /* How an item is bought and counted: "1 kg bag", "crate of 24". */
        Schema::create('stock_item_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // How much of the base unit one pack holds.
            $table->bigInteger('qty');
            $table->timestamps();
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->foreignId('stock_item_id')->constrained();
            $table->string('batch_no', 60);
            $table->date('expiry_date')->nullable();
            $table->date('made_on')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'stock_item_id', 'batch_no']);
            $table->index(['outlet_id', 'expiry_date']);
        });

        /*
        | How much of an item one store holds, per batch. `batch_id` is 0 for
        | stock with no batch, so the unique index below can do its job - a
        | NULL would let two rows through.
        */
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->foreignId('store_id')->constrained();
            $table->foreignId('stock_item_id')->constrained();
            $table->unsignedBigInteger('batch_id')->default(0);
            $table->bigInteger('qty')->default(0);
            // Weighted average of what is in this slot.
            $table->bigInteger('cost_rate')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'stock_item_id', 'batch_id']);
            $table->index(['outlet_id', 'stock_item_id']);
        });

        /*
        | The ledger: one row for every change to a level, never edited and
        | never deleted. A movement made from an event carries the event's id,
        | the line it was about and which part of that line it was, under a
        | unique index - so applying the same event twice cannot post twice.
        */
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->foreignId('store_id')->constrained();
            $table->foreignId('stock_item_id')->constrained();
            $table->unsignedBigInteger('batch_id')->default(0);
            $table->string('type', 24);
            // Signed: in is positive, out is negative.
            $table->bigInteger('qty');
            $table->bigInteger('cost_rate')->default(0);
            // Signed, like qty.
            $table->bigInteger('value_paise')->default(0);
            // The slot's level after this movement.
            $table->bigInteger('balance_qty');
            $table->nullableMorphs('reference');
            $table->char('event_id', 26)->nullable();
            $table->string('event_line', 64)->nullable();
            $table->unsignedSmallInteger('event_part')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->date('business_date');
            $table->dateTime('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['event_id', 'event_line', 'event_part']);
            $table->index(['outlet_id', 'stock_item_id', 'occurred_at']);
            $table->index(['outlet_id', 'type', 'business_date']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'stock_levels', 'batches', 'stock_item_packs', 'stock_items', 'outlet_settings', 'stores'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
