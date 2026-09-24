<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the guest thought (SRS 15).
     *
     * ------------------------------------------------------------------
     * Left deliberately easy to give
     * ------------------------------------------------------------------
     *
     * Everything here except the rating is nullable, including who left it.
     * A diner tapping one star on their way out of the door is the most
     * honest feedback a restaurant ever gets, and a form that asked for a
     * name and an email first would collect nothing but praise from people
     * with time to spare.
     *
     * The session it came from is recorded where there is one, which links
     * the rating to the table, the order and the evening without the guest
     * being asked a thing.
     *
     * ------------------------------------------------------------------
     * One per sitting
     * ------------------------------------------------------------------
     *
     * Unique on the session, so a phone left on a table cannot leave forty
     * one-star ratings, and a guest who changes their mind updates rather
     * than adds. Walk-up feedback with no session is unconstrained, because
     * there is nothing to constrain it by.
     */
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->foreignId('table_session_id')->nullable()
                ->constrained('table_sessions')->nullOnDelete();

            $table->foreignId('order_id')->nullable()
                ->constrained('orders')->nullOnDelete();

            $table->foreignId('customer_id')->nullable()
                ->constrained()->nullOnDelete();

            /*
             | The only required field. One to five, because anything more
             | granular is a survey and gets answered by nobody.
             */
            $table->unsignedTinyInteger('rating');

            // Split ratings, optional. A kitchen and a floor fail separately
            // and a single number hides which.
            $table->unsignedTinyInteger('food_rating')->nullable();
            $table->unsignedTinyInteger('service_rating')->nullable();

            $table->text('comment')->nullable();

            $table->string('guest_name', 120)->nullable();
            $table->string('guest_mobile', 30)->nullable();

            /*
             | The manager's reply, and when. A complaint somebody answered is
             | worth more than one nobody saw, and this is what makes the
             | difference visible on a list.
             */
            $table->text('response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('responded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('table_session_id');
            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
