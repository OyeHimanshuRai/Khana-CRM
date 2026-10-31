<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time codes (SRS 3.8, 14).
     *
     * ------------------------------------------------------------------
     * The code is stored hashed
     * ------------------------------------------------------------------
     *
     * A six-digit number in a database column is readable by every backup,
     * every replica and everybody with a query tool - which for the ten
     * minutes it lives is the same as publishing it. It is hashed for the
     * same reason a password is, and compared the same way.
     *
     * The cost is that nobody can look up a code to help a guest who did not
     * receive one. That is the right trade: the answer to "I did not get it"
     * is to send another, which is one button.
     *
     * ------------------------------------------------------------------
     * Attempts are counted on the row, not in a cache
     * ------------------------------------------------------------------
     *
     * A cache is the obvious place and the wrong one: it is cleared by a
     * deploy, and a guess limit that resets when somebody restarts a queue
     * worker is not a limit. Five wrong guesses burn this row and the guest
     * asks for a new code.
     */
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();

            /*
             | What is being verified. Polymorphic because the next thing to
             | need a code will not be a table sitting - a reservation
             | confirming a booking, a customer claiming a loyalty account -
             | and none of them should need their own table.
             */
            $table->nullableMorphs('verifiable');

            // table_session | reservation | login
            $table->string('purpose', 40)->index();

            $table->string('channel', 20)->default('sms');

            /*
             | Where it was sent. Kept in the clear: it is the number the
             | guest typed in and will type again, the resend throttle reads
             | it, and it is already on the table session.
             */
            $table->string('destination', 30);

            $table->string('code_hash');

            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();

            // Null when the provider refused, which is not the same as never
            // having tried - the guest is told a different thing for each.
            $table->timestamp('sent_at')->nullable();

            $table->string('ip', 45)->nullable();

            $table->timestamps();

            // The resend throttle and the verify lookup, both of which ask
            // "the newest live code for this number and purpose".
            $table->index(['destination', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
