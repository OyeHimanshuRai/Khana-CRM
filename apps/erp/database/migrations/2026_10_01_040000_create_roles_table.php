<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The roles a business has made for itself, beside the presets every
 * business gets (App\Support\Roles::PRESETS, which are code and have no rows).
 *
 * `key` is what Accounts stores against a person and hands back at sign-in,
 * so it is fixed once made: renaming a role changes `name` only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26);
            // A contract `code`: lower case, digits and underscores.
            $table->string('key', 40);
            $table->string('name', 60);
            // The abilities it carries, from App\Support\Roles::ABILITIES.
            $table->json('abilities');
            // The preset it was copied from, for the record.
            $table->string('based_on', 40)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
