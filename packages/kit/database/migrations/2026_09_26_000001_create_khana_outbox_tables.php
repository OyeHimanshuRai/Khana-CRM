<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The sending half of the event plumbing.
|
| An event row is written inside the same transaction as the change it
| describes, so a rolled-back change leaves no event behind. It gets its
| `seq` later, from the sequencer, once it is committed and visible: numbering
| at insert time would let a transaction that started first but committed last
| land behind a cursor that had already moved past it, and that event would
| never be sent.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('khana_outbox', function (Blueprint $table) {
            $table->id();
            $table->char('event_id', 26)->unique();
            $table->unsignedBigInteger('seq')->nullable()->unique();
            $table->string('type', 100);
            $table->unsignedSmallInteger('version');
            $table->char('tenant_id', 26)->nullable();
            $table->char('outlet_id', 26)->nullable();
            $table->dateTime('occurred_at');
            $table->json('data');
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // One row, locked while numbers are handed out, holding the last one.
        Schema::create('khana_outbox_sequence', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_seq')->default(0);
        });

        DB::table('khana_outbox_sequence')->insert(['id' => 1, 'last_seq' => 0]);

        // How far each receiver has got. Advances only on a 2xx.
        Schema::create('khana_outbox_cursors', function (Blueprint $table) {
            $table->string('subscriber', 40)->primary();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('khana_outbox_cursors');
        Schema::dropIfExists('khana_outbox_sequence');
        Schema::dropIfExists('khana_outbox');
    }
};
