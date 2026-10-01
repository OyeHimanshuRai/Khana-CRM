<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is sold, what it is made of, and what was sold or cooked.
 *
 * ERP does not own the menu. A sales item is the menu as ERP sees it - copied
 * from CRM by event, or made from a CSV import or the sales API for an
 * outlet that bills with something else. A recipe is what joins a sales item
 * to the stock items it is made of, and is the one thing here that ERP's
 * own people write.
 *
 * `ref` columns hold the other system's id for the row: a ULID from CRM, or
 * whatever the other billing software calls it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlet_settings', function (Blueprint $table) {
            // When off (the default), a dish is cooked from wherever the
            // stock is: the kitchen first, then the other stores. When on,
            // only what has been issued to the kitchen can be cooked with.
            $table->boolean('kitchen_only')->default(false);
            // How this outlet's CSV sales file is laid out, remembered.
            $table->json('csv_layout')->nullable();
        });

        Schema::create('sales_items', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            // crm | csv | api
            $table->string('source', 8);
            $table->string('ref', 100);
            $table->string('name', 200);
            $table->string('category', 200)->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->bigInteger('price_paise')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'source', 'ref']);
        });

        Schema::create('sales_item_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_item_id')->constrained()->cascadeOnDelete();
            $table->string('ref', 100);
            $table->string('name', 200);
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->bigInteger('price_paise')->default(0);
            $table->boolean('is_default')->default(false);
            // A size CRM no longer lists is kept, for the sales that name it.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['sales_item_id', 'ref']);
        });

        /* An add-on option: "extra cheese". One row per outlet, whichever dishes offer it. */
        Schema::create('sales_addons', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->string('source', 8);
            $table->string('ref', 100);
            $table->string('group_name', 200)->nullable();
            $table->string('name', 200);
            $table->bigInteger('price_paise')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'source', 'ref']);
        });

        /*
        | What something is made of. Exactly one of three things has a recipe:
        |
        |   a dish     sales_item_id, with sales_item_size_id for one size's
        |              own recipe or 0 for the recipe every size shares
        |   an add-on  sales_addon_id
        |   prepared   stock_item_id - a gravy base made in-house; yield_qty
        |              is how much of it one batch of the recipe makes
        |
        | The ids are 0 rather than NULL where they do not apply, so the
        | unique index can hold one recipe to one target.
        */
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->unsignedBigInteger('sales_item_id')->default(0);
            $table->unsignedBigInteger('sales_item_size_id')->default(0);
            $table->unsignedBigInteger('sales_addon_id')->default(0);
            $table->unsignedBigInteger('stock_item_id')->default(0);
            // One portion for a dish or an add-on; a batch for a prepared item.
            $table->bigInteger('yield_qty')->default(10_000);
            $table->string('notes', 500)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'sales_item_id', 'sales_item_size_id', 'sales_addon_id', 'stock_item_id'], 'recipes_target_unique');
        });

        Schema::create('recipe_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained();
            $table->bigInteger('qty');
            $table->string('entered_qty', 20);
            $table->string('entered_unit', 20);
            // A garnish: used, but running out of it does not stop the dish.
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->index('stock_item_id');
        });

        /*
        | What ERP last told CRM about a dish: whether stock can still cover a
        | portion, and what a portion costs. Kept so that only a change is
        | sent. size id 0 is a dish with no sizes.
        */
        Schema::create('sales_item_states', function (Blueprint $table) {
            $table->id();
            $table->char('outlet_id', 26);
            $table->foreignId('sales_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sales_item_size_id')->default(0);
            $table->boolean('available')->default(true);
            $table->bigInteger('cost_paise')->nullable();
            $table->timestamps();

            $table->unique(['sales_item_id', 'sales_item_size_id']);
        });

        /*
        | One line of one kitchen order, as CRM reports it stage by stage.
        | Consumed once, at the stage the outlet chose - `consumed_at` is what
        | makes it once.
        */
        Schema::create('kitchen_lines', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->char('ref', 26);
            $table->char('order_ref', 26)->nullable();
            $table->unsignedBigInteger('sales_item_id')->default(0);
            $table->unsignedBigInteger('sales_item_size_id')->default(0);
            $table->string('item_ref', 100)->nullable();
            $table->string('variant_ref', 100)->nullable();
            $table->json('option_refs')->nullable();
            // Portions, in ten-thousandths.
            $table->bigInteger('qty')->default(0);
            $table->string('stage', 12)->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->bigInteger('cost_paise')->default(0);
            $table->dateTime('cancelled_at')->nullable();
            // Cooking had begun when it was cancelled: the food is wastage.
            $table->boolean('wasted')->default(false);
            // The bill this line ended up on, once there is one.
            $table->unsignedBigInteger('sale_line_id')->nullable()->index();
            $table->date('business_date')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'ref']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->string('source', 8);
            // CRM's sale id, or the other system's reference.
            $table->string('ref', 100);
            $table->string('invoice_no', 50);
            $table->date('business_date');
            $table->string('channel', 12);
            $table->bigInteger('net_paise')->default(0);
            $table->bigInteger('tax_paise')->default(0);
            $table->bigInteger('round_off_paise')->default(0);
            $table->bigInteger('grand_paise')->default(0);
            // recorded | cancelled
            $table->string('status', 10)->default('recorded');
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedBigInteger('sales_import_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['outlet_id', 'source', 'ref']);
            $table->index(['outlet_id', 'business_date']);
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('ref', 100)->nullable();
            $table->unsignedBigInteger('sales_item_id')->default(0);
            $table->unsignedBigInteger('sales_item_size_id')->default(0);
            $table->string('item_ref', 100)->nullable();
            $table->string('variant_ref', 100)->nullable();
            $table->json('option_refs')->nullable();
            $table->string('name', 200);
            $table->bigInteger('qty');
            $table->bigInteger('net_paise')->default(0);
            $table->bigInteger('tax_paise')->default(0);
            // The kitchen lines this bill line settles; empty for something
            // that never went through the kitchen.
            $table->json('kitchen_refs')->nullable();
            // What this line itself took out of stock (not the kitchen's).
            $table->bigInteger('cost_paise')->default(0);
            $table->bigInteger('returned_qty')->default(0);
            $table->timestamps();

            $table->index(['sales_item_id', 'sales_item_size_id']);
            $table->index('ref');
        });

        /* A CSV of sales from other billing software, and what became of it. */
        Schema::create('sales_imports', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            $table->string('file_name');
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('sales')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });

        /*
        | "Paneer Tikka (F)" in their file is our Paneer Tikka, Full - matched
        | once and remembered.
        */
        Schema::create('sales_item_aliases', function (Blueprint $table) {
            $table->id();
            $table->char('outlet_id', 26);
            $table->string('alias', 200);
            $table->foreignId('sales_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sales_item_size_id')->default(0);
            $table->timestamps();

            $table->unique(['outlet_id', 'alias']);
        });

        /*
        | The paperwork of the store room: an issue to the kitchen, a wastage
        | note, a production batch, a stock count. One table, because each is
        | "these items, this much of each, on this day, by this person" - the
        | ledger rows they caused point back here.
        */
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('outlet_id', 26);
            // issue | wastage | production | count
            $table->string('kind', 12);
            $table->string('number', 30);
            $table->date('on_date');
            $table->foreignId('store_id')->constrained();
            $table->unsignedBigInteger('to_store_id')->nullable();
            $table->string('note', 500)->nullable();
            // Money: what was wasted, made or found missing.
            $table->bigInteger('value_paise')->default(0);
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'number']);
            $table->index(['outlet_id', 'kind', 'on_date']);
        });

        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained();
            $table->unsignedBigInteger('batch_id')->default(0);
            // What moved - or, on a count, the difference found.
            $table->bigInteger('qty');
            // A count only: what the books said, and what was on the shelf.
            $table->bigInteger('expected_qty')->nullable();
            $table->bigInteger('counted_qty')->nullable();
            $table->bigInteger('value_paise')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'stock_document_lines', 'stock_documents', 'sales_item_aliases', 'sales_imports', 'sale_lines', 'sales',
            'kitchen_lines', 'sales_item_states', 'recipe_lines', 'recipes', 'sales_addons', 'sales_item_sizes', 'sales_items',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('outlet_settings', fn (Blueprint $table) => $table->dropColumn(['kitchen_only', 'csv_layout']));
    }
};
