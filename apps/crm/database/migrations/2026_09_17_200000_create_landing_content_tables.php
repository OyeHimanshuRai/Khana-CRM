<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The six things the landing page was missing (§19).
     *
     * ------------------------------------------------------------------
     * One migration, six tables, because it is one feature
     * ------------------------------------------------------------------
     *
     * They are created together because they exist for one reason - a public
     * page that sells the product - and nothing else in the system reads them.
     * Splitting them into six files would say they arrived independently, and
     * the next person would have to open all six to find out they did not.
     *
     * Separate tables rather than one `landing_blocks` row with a JSON blob:
     * a testimonial has an author and a rating, an integration has a logo and
     * a link, a statistic has a number. Collapsing five different shapes into
     * `title`/`subtitle`/`data` makes every admin form branch on a `kind`
     * column and every validation rule optional, which is how a quote ends up
     * saved with nobody's name on it.
     *
     * None of them carry BelongsToShop. They are platform-level marketing
     * content, like the existing Slider and Faq tables, and the page that
     * renders them has no signed-in user and therefore no shop.
     */
    public function up(): void
    {
        /*
         | What customers say (§19).
         |
         | `image_path` is a face, not a logo, and is optional - plenty of
         | owners will give a quote and not a photograph, and a card that
         | demanded one would collect nothing.
         */
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();

            $table->text('quote');

            // The only required attribution. An unattributed quote on a
            // marketing page is worth less than no quote.
            $table->string('author_name', 120);
            $table->string('author_role', 120)->nullable();
            $table->string('company', 120)->nullable();

            /*
             | Out of five, and nullable. A testimonial is not a review: most
             | are collected by asking, and printing "5/5" against every one of
             | them is the fastest way to make all of them look invented.
             */
            $table->unsignedTinyInteger('rating')->nullable();

            $table->string('image_path')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        /*
         | The kinds of business this suits (§19).
         |
         | Fine dining, QSR, cafe, cloud kitchen, bakery, bar. It is the
         | cheapest section on the page and does the most work: a reader
         | recognising their own format is most of the sale.
         */
        Schema::create('outlet_types', function (Blueprint $table) {
            $table->id();

            $table->string('name', 90);
            $table->string('blurb', 200)->nullable();

            /*
             | A key from config/icons.php rather than an upload. These are
             | listed six or nine at a time and want to look like one set;
             | six uploaded images never do.
             */
            $table->string('icon', 40)->default('cart');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        /*
         | What it plugs into (§19).
         |
         | The logo IS the content here - nobody reads the name of a payment
         | gateway they already use, they recognise the mark - so `image_path`
         | is the field that matters and the name is the alt text.
         */
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();

            $table->string('name', 90);

            // "Payments", "Delivery", "Accounting". Free text, because the
            // list of categories is not something to migrate for.
            $table->string('category', 40)->nullable();

            $table->string('url')->nullable();
            $table->string('image_path')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        /*
         | Screenshots of the product (§19).
         |
         | Deliberately not the Slider table. A slider slot is a hero banner
         | with a link and a layout; this is a picture of a screen with a
         | sentence under it, and the two would have fought over `layout`.
         */
        Schema::create('showcases', function (Blueprint $table) {
            $table->id();

            $table->string('title', 120);
            $table->string('caption', 250)->nullable();

            // Required in practice - a screenshot section with no screenshot
            // is nothing - but nullable so the row survives its file being
            // swept off disk. See the model's imageUrl().
            $table->string('image_path')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        /*
         | Trust numbers (§19).
         |
         | ------------------------------------------------------------------
         | `source` is the honest half of this table
         | ------------------------------------------------------------------
         |
         | `value` is a string, not an integer, because these are typed by a
         | marketer: "1,50,000+", "24/7", "99.9%". None of those are numbers.
         |
         | But a typed number is a claim nobody checks again, and it goes stale
         | the day it is entered. So a row may instead name a `source` - a key
         | this application can count for itself - and the page then prints the
         | real figure. A statistic the software works out cannot be wrong by
         | the time somebody reads it.
         */
        Schema::create('landing_stats', function (Blueprint $table) {
            $table->id();

            $table->string('label', 90);

            // Ignored when `source` is set. Kept anyway, as the fallback for
            // a source that cannot be counted on this deployment.
            $table->string('value', 40)->nullable();

            $table->string('source', 40)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        /*
         | "Book a demo" (§19).
         |
         | The only table here somebody writes to from the public internet, and
         | the only one that is not content - it is a sales lead, and losing one
         | is losing money.
         |
         | Everything except the name and one way to reach them is nullable. A
         | form that demands a city and a business name before it will take an
         | enquiry collects fewer enquiries, and the missing fields are exactly
         | what the first phone call is for.
         */
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);

            /*
             | One of these two is required, enforced in the request rather
             | than here: "email or phone" is not a constraint SQL expresses
             | portably, and a CHECK that MySQL and SQLite disagree about is
             | worse than a validated rule with a test on it.
             */
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();

            $table->string('city', 90)->nullable();
            $table->string('business_name', 150)->nullable();

            // How many branches they run. Decides who calls them back.
            $table->unsignedSmallInteger('outlets')->nullable();

            $table->text('message')->nullable();

            $table->string('status', 20)->default('new');

            /*
             | Who picked it up, and when. Nullable on delete rather than
             | cascade: a lead outlives the salesperson who handled it.
             */
            $table->foreignId('handled_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();

            $table->text('note')->nullable();

            /*
             | Recorded for abuse, not for marketing. A public form gets found
             | by bots, and without this there is no way to tell forty
             | enquiries from one script apart from forty restaurants.
             */
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            // The inbox: new work first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
        Schema::dropIfExists('landing_stats');
        Schema::dropIfExists('showcases');
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('outlet_types');
        Schema::dropIfExists('testimonials');
    }
};
