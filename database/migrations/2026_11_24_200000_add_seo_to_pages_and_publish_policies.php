<?php

use App\Support\DefaultPages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every public page gets a search title and keywords of its own, and the three
 * pages a business site is expected to carry - Terms and Conditions, Privacy
 * Policy, Refund and Cancellation Policy - are written (once) so the footer and
 * the sign-up form have something to link to.
 *
 * Only added when missing, and never overwritten: the platform owner edits them
 * under Site Pages from then on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('meta_title', 160)->nullable()->after('meta_description');
            $table->string('meta_keywords', 300)->nullable()->after('meta_title');
        });

        // A test database starts with no pages: the tests that need them make their own.
        if (! app()->runningUnitTests()) {
            DefaultPages::publish();
        }
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_keywords']);
        });
    }
};
