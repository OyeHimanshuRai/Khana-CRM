<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the platform sells (SRS 2, 16, 21).
     *
     * A plan is a price list entry, not a switch. It says what a business
     * gets for its money - which modules, how many branches, how many staff
     * - and a subscription is one business having bought one of these.
     *
     * Deliberately NOT tenant-scoped and NOT shop-scoped. This is the
     * platform's own catalogue, the same for everybody, and the only people
     * who may touch it are Super Admins. A tenant reads its plan through its
     * subscription and can never edit one.
     *
     * ------------------------------------------------------------------
     * Null means "no ceiling", everywhere
     * ------------------------------------------------------------------
     *
     * Every limit here is nullable, and null is unlimited rather than zero.
     * That matters because zero is a real answer somebody might want
     * ("this plan gets no extra branches") and a column that could not tell
     * the two apart would make the generous case unexpressible. An
     * enterprise plan leaves them null; a starter plan fills them in.
     *
     * `modules` follows the same rule as shops.modules: null is every
     * module, [] is none. An install that predates this table has no
     * subscription at all and is therefore unlimited - going dark on upgrade
     * is the one outcome worse than any limit.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);

            // Quoted in support conversations and used by the seeder to find
            // a plan again without depending on its id.
            $table->string('code', 40)->unique();
            $table->string('slug', 140)->unique();

            // One line for the plan chooser, in the seller's own words.
            $table->string('blurb', 255)->nullable();

            /* --------------------------------------------------------- money */

            $table->decimal('monthly_price', 12, 2)->default(0);

            /*
             | Nullable rather than derived. A yearly price is a discount
             | decision, not twelve times the monthly one, and a plan that is
             | genuinely monthly-only should be able to say so by leaving
             | this empty rather than by pricing itself out of the year.
             */
            $table->decimal('yearly_price', 12, 2)->nullable();

            $table->string('currency', 8)->default('INR');

            // 0 is normal: most plans are sold, not sampled.
            $table->unsignedSmallInteger('trial_days')->default(0);

            /* ------------------------------------------------ feature access */

            /*
             | The modules this plan grants, from config/modules.php.
             |
             | It caps what a branch may switch on; it does not switch
             | anything on. A shop still chooses from its own list, and the
             | answer is the overlap - see App\Support\Modules::forShop.
             | Selling somebody a feature does not mean every one of their
             | branches wants it.
             */
            $table->json('modules')->nullable();

            /* -------------------------------------------------------- limits */

            $table->unsignedInteger('max_shops')->nullable();
            $table->unsignedInteger('max_users')->nullable();

            /*
             | Checked against the calendar month, and the softest of the
             | three: a kitchen mid-service is not stopped from billing a
             | table because a counter ticked over. It warns, it reports, and
             | it is what a sales conversation is made of.
             */
            $table->unsignedInteger('max_orders_per_month')->nullable();

            /* --------------------------------------------------- operational */

            // An inactive plan keeps its existing subscribers and stops
            // being offered to new ones. Withdrawing a plan from sale must
            // never be the same act as cancelling everybody on it.
            $table->boolean('is_active')->default(true)->index();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['sort_order', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
