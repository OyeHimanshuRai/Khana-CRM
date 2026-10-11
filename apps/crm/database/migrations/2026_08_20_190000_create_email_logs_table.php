<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             | Metadata only - there is deliberately no body column here.
             |
             | The welcome email carries a one-time password-set link, and
             | password resets carry another. Storing rendered bodies would
             | put working credentials into a table that anyone holding
             | email.logs.view can read, turning a delivery log into a
             | privilege escalation route. Subject, recipient and outcome
             | answer every question this screen exists for.
             */

            // e.g. App\Mail\WelcomeUserMail - what kind of email this was.
            $table->string('mailable')->nullable()->index();

            $table->string('to_email')->index();
            $table->string('to_name', 160)->nullable();
            // Only set when a message went to more than one To address.
            $table->text('to_extra')->nullable();

            $table->text('cc')->nullable();
            $table->text('bcc')->nullable();

            $table->string('from_email')->nullable();
            $table->string('from_name', 160)->nullable();
            $table->string('reply_to')->nullable();

            // Subjects are not length-limited by the RFC in any useful way.
            $table->string('subject', 500)->nullable();

            // Which configured transport carried it.
            $table->string('mailer', 40)->nullable();

            /*
             | pending | sent | failed
             |
             | A row is created when the send starts and flipped when it
             | finishes. Anything still `pending` neither confirmed nor
             | reported an error - usually a process that died mid-send.
             */
            $table->string('status', 20)->default('pending')->index();
            $table->text('error')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // The listing's default ordering, filtered by status.
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
