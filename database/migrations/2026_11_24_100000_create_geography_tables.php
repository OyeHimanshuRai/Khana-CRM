<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Countries, their states and cities, plus the calling code and the length of a
 * national phone number for each country - for the sign-up form's dependent
 * dropdowns and mobile-number rules.
 *
 * The rows come from database/data/geography.json (the same data as
 * database/sql/geography.sql, for importing by hand). Every country and state
 * is there; cities are listed for the countries the product sells in and its
 * neighbours (see the file). The users table gains the calling code the owner
 * signed up with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('country_name', 100);
            $table->char('iso_code', 2)->unique();
            $table->string('phone_code', 8);
            $table->unsignedTinyInteger('min_length')->default(4);
            $table->unsignedTinyInteger('max_length')->default(15);
            $table->index('country_name');
        });

        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('state_name', 120);
            $table->index(['country_id', 'state_name']);
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained('states')->cascadeOnDelete();
            $table->string('city_name', 150);
            $table->index(['state_id', 'city_name']);
        });

        $data = json_decode((string) file_get_contents(database_path('data/geography.json')), true);

        foreach (array_chunk($data['countries'], 500) as $chunk) {
            DB::table('countries')->insert(array_map(fn ($r) => [
                'id' => $r[0], 'country_name' => $r[1], 'iso_code' => $r[2],
                'phone_code' => $r[3], 'min_length' => $r[4], 'max_length' => $r[5],
            ], $chunk));
        }

        foreach (array_chunk($data['states'], 500) as $chunk) {
            DB::table('states')->insert(array_map(fn ($r) => [
                'id' => $r[0], 'country_id' => $r[1], 'state_name' => $r[2],
            ], $chunk));
        }

        foreach (array_chunk($data['cities'], 1000) as $chunk) {
            DB::table('cities')->insert(array_map(fn ($r) => [
                'id' => $r[0], 'state_id' => $r[1], 'city_name' => $r[2],
            ], $chunk));
        }

        if (! Schema::hasColumn('users', 'mobile_country_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('mobile_country_code', 8)->nullable()->after('mobile');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'mobile_country_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('mobile_country_code');
            });
        }

        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
    }
};
