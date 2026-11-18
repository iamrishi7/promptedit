<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Pixelforge answered a submit attempt with "busy": tells "it stayed too busy" apart from
            // "we never managed to send it" when submission is abandoned.
            $table->boolean('provider_said_busy')->default(false)->after('submission_outcome_unknown');
            // Our own copy of the image (Pixelforge's link expires 24 hours after completion).
            $table->text('stored_image_path')->nullable()->after('image_url_expires_at');
        });

        // The poller's "images still to store" sweep stays an index scan however many images are stored.
        DB::statement("CREATE INDEX generations_image_unstored ON generations (completed_at) WHERE status = 'succeeded' AND stored_image_path IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS generations_image_unstored');

        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['provider_said_busy', 'stored_image_path']);
        });
    }
};
