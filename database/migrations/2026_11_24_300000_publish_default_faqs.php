<?php

use App\Support\DefaultFaqs;
use Illuminate\Database\Migrations\Migration;

/**
 * The questions on the FAQ page are written (once) so the page is not empty on
 * a new install. Super Admin edits them under Content > FAQs from then on; a
 * question that is already there is left exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A test database starts with no FAQs: the tests that need them make their own.
        if (! app()->runningUnitTests()) {
            DefaultFaqs::publish();
        }
    }

    public function down(): void
    {
        // Nothing to undo: these are ordinary rows the owner may have edited.
    }
};
