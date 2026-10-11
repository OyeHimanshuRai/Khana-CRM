<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Who may do what, in which product, at which outlets - set once, here, and
| copied to CRM and ERP by accounts.access.changed. Nobody sets people up
| twice.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // crm | erp
            $table->string('product', 10);
            // A preset (owner, manager, cashier...) or a role the product's
            // owner made. The product holds what the role may do.
            $table->string('role_key', 40);
            // Every outlet, including ones opened later - an owner's access.
            $table->boolean('all_outlets')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'product']);
        });

        Schema::create('product_access_outlets', function (Blueprint $table) {
            $table->foreignId('product_access_id')->constrained('product_access')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            $table->primary(['product_access_id', 'outlet_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The person, already created as "invited" so their access can be
            // set and copied before they accept.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // sha256 of the token in the link; the link itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('product_access_outlets');
        Schema::dropIfExists('product_access');
    }
};
