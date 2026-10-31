<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prices that apply only sometimes (SRS 8, 16).
     *
     * ------------------------------------------------------------------
     * What this is, and what it is not
     * ------------------------------------------------------------------
     *
     * §8 already gives every dish a dine-in, takeaway and online price, and
     * those cover the axis most restaurants care about. What they cannot
     * express is "half price on beer between four and seven", which is the
     * other axis every bar in the country uses.
     *
     * So a price list is a named set of overrides with a window: some days,
     * some hours, optionally some date range, optionally one channel. Inside
     * the window its prices win; outside it nothing changes at all.
     *
     * ------------------------------------------------------------------
     * Nothing changes until somebody makes one
     * ------------------------------------------------------------------
     *
     * The rule this table lives under. With no active list the price a till
     * shows is exactly the price it showed before this existed - see
     * PriceListService, which returns null for "no override" and lets the
     * product's own price stand.
     *
     * That matters because the override reaches everything: the counter, the
     * QR menu, the API and every order placed through any of them. A feature
     * with that reach has to be inert by default.
     */
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('name', 90);
            $table->string('code', 40);

            /*
             | The window.
             |
             | All four parts are optional and they narrow independently: a
             | list with none of them set is simply always on, which is the
             | right way to express a permanent second price list.
             */
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // "16:00" to "19:00". Stored as time, compared as time.
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            /*
             | Weekdays it runs, as ISO numbers (1 = Monday). Null is every
             | day - distinct from [], which would be a list that runs on no
             | day at all and is a thing somebody might genuinely configure
             | while building one.
             */
            $table->json('weekdays')->nullable();

            // dine_in | takeaway | online, or null for all of them.
            $table->string('channel', 20)->nullable();

            /*
             | Which list wins when two overlap.
             |
             | Two lists covering the same dish at the same moment is not an
             | error - a Tuesday offer and a happy hour can genuinely both
             | apply - so somebody has to say which one the guest gets.
             | Highest first, and the id breaks a tie so the answer is at
             | least stable.
             */
            $table->unsignedSmallInteger('priority')->default(0);

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'code']);
            $table->index(['shop_id', 'is_active', 'priority']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // A size that is priced separately. Null covers the dish itself.
            $table->foreignId('product_variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();

            /*
             | One or the other, never both.
             |
             | A flat price is what a bar writes on a chalkboard; a percentage
             | is what a manager sets across forty dishes without pricing each
             | one. Storing both and picking would mean two sources of truth
             | for one number, so the service refuses a row with both.
             */
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();

            $table->timestamps();

            $table->unique(['price_list_id', 'product_id', 'product_variant_id'], 'price_list_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
