<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Who has to prove their email address before the account opens: a business
| that signed itself up. Off for everybody else - staff an owner or the
| platform created were vouched for by whoever created them - and off for
| every account that exists today, so nobody is locked out on upgrade.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_verify_email')->default(false)->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_verify_email');
        });
    }
};
