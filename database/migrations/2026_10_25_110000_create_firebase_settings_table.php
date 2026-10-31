<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform's Firebase project, for push notifications.
     *
     * One row - see App\Models\FirebaseSetting for why it is the platform's
     * and not each restaurant's.
     *
     * `service_account` is text, not json: it holds the encrypted envelope of
     * the JSON file (the `encrypted:array` cast), which is not itself JSON a
     * database could query - and should not be.
     */
    public function up(): void
    {
        Schema::create('firebase_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);

            // The web app's firebaseConfig - public by Firebase's design.
            $table->string('project_id', 120)->nullable();
            $table->string('api_key', 120)->nullable();
            $table->string('auth_domain', 190)->nullable();
            $table->string('storage_bucket', 190)->nullable();
            $table->string('messaging_sender_id', 40)->nullable();
            $table->string('app_id', 120)->nullable();
            $table->string('measurement_id', 40)->nullable();
            $table->string('vapid_key', 200)->nullable();

            // The private half. Encrypted with APP_KEY.
            $table->text('service_account')->nullable();

            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result', 190)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firebase_settings');
    }
};
