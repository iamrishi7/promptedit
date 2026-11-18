<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Inbox of Pixelforge webhook events. The event id is the primary key, so a duplicate delivery can
     * never be recorded (or applied) twice.
     */
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('type', 64);
            $table->jsonb('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 32)->nullable();
        });

        DB::statement('ALTER TABLE provider_events ADD CONSTRAINT provider_events_outcome_iff_processed CHECK ((processed_at IS NULL) = (outcome IS NULL))');

        // Sweeper: events received but not yet applied (e.g. the job crashed).
        DB::statement('CREATE INDEX provider_events_unprocessed ON provider_events (received_at) WHERE processed_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
