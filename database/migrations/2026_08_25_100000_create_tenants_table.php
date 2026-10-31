<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company master, one level above shops.
     *
     * The SRS calls this a tenant: one business, which may run
     * several branches. Until now `shops` was the top of the tree and carried
     * the company's own identity - name, GSTIN, PAN, logo, invoice series -
     * because with one business there was nowhere else to put it.
     *
     * With more than one business on the same install those fields belong
     * here: the legal entity is the company, not the branch. A branch keeps
     * its own address, its own invoice series (two branches billing at the
     * same second must not mint the same number) and its own stock, and
     * inherits everything else from its tenant.
     *
     * Isolation is enforced through the branch rather than by stamping
     * tenant_id on all forty operational tables. Every operational row
     * already carries shop_id, every shop carries tenant_id, and a user can
     * only ever reach shops inside their own tenant - see CurrentShop. That
     * gives the same guarantee the SRS asks for with one join instead of a
     * column nobody would remember to filter on.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);

            // Short key used in branch codes and the tenant switcher.
            $table->string('code', 20)->unique();
            $table->string('slug', 160)->unique();

            $table->string('legal_name', 180)->nullable();

            /* ------------------------------------------------ tax identity */

            // The company's registration. A branch may hold its own GSTIN
            // when it is separately registered, and overrides this.
            $table->string('gstin', 20)->nullable();
            $table->string('pan', 15)->nullable();

            /* ------------------------------------------------------ contact */

            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();

            $table->string('address_line1', 190)->nullable();
            $table->string('address_line2', 190)->nullable();
            $table->string('city', 90)->nullable();
            $table->string('state', 90)->nullable();
            $table->string('state_code', 4)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->string('country', 90)->default('India');

            /* ------------------------------------------------------ branding */

            // Printed on every branch's invoice unless the branch sets its own.
            $table->string('logo_path')->nullable();

            /* -------------------------------------------------- operational */

            $table->string('currency', 8)->default('INR');
            $table->string('timezone', 64)->default('Asia/Kolkata');

            /*
             | SaaS lifecycle. A suspended tenant keeps all its data and stops
             | being able to sign in - deleting a business's ledger
             | because an invoice went unpaid is not a recoverable mistake.
             */
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspend_reason', 190)->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['sort_order', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
