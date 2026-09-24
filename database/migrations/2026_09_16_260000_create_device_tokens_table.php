<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devices that can be pushed to (SRS 16).
     *
     * ------------------------------------------------------------------
     * What this is actually for
     * ------------------------------------------------------------------
     *
     * The kitchen display already beeps and raises a browser notification
     * while its tab is open - see public/assets/js/kds.js. Push adds exactly
     * one thing on top of that, and it is worth being precise about it: it
     * reaches a tablet whose screen has gone off between rushes, which is
     * every kitchen tablet at half past three.
     *
     * Nothing else in this system needs it. A guest is not asked for
     * notification permission mid-meal, and a manager reading a report does
     * not want their phone buzzing.
     *
     * ------------------------------------------------------------------
     * The endpoint is the identity
     * ------------------------------------------------------------------
     *
     * A Web Push subscription is a URL the browser vendor gives out, plus two
     * keys. The URL is unique per browser per site and is what the push
     * service is addressed by, so it is the unique column - not the user, who
     * may have three tablets, and not the device, which has no id we can see.
     *
     * Subscriptions expire and are revoked constantly: a browser update, a
     * cleared site setting, a reinstalled tablet. A 404 or 410 from the push
     * service means "this is gone", and PushService deletes the row rather
     * than retrying something that can never work again.
     */
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            /*
             | Who was signed in when the device subscribed. Null is allowed
             | because a kitchen tablet often runs on a shared account that
             | somebody later deletes, and losing the subscription with it
             | would silence the screen for no reason anybody could trace.
             */
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // web_push today; fcm or apns if a native app ever arrives.
            $table->string('kind', 20)->default('web_push');

            /*
             | The push service's own URL for this browser. Long: Chrome's
             | run to about 200 characters and there is no specified maximum,
             | so the column is generous. What is indexed is the hash below.
             */
            $table->string('endpoint', 500);

            /*
             | sha256 of the endpoint, and what the unique index is actually
             | on.
             |
             | The endpoint itself is too long for a utf8mb4 index and a
             | prefix index is both MySQL-only and a guess about where two
             | URLs stop differing. A hash is fixed width, portable, and
             | exactly as unique as the thing it hashes.
             */
            $table->char('endpoint_hash', 64)->unique();

            // The two keys the payload is encrypted with.
            $table->string('p256dh', 255)->nullable();
            $table->string('auth', 255)->nullable();

            // Which screen this is, so a list of four tablets is readable.
            $table->string('label', 120)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
