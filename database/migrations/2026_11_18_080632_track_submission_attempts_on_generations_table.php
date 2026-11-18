<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Counts instead of a "said busy" flag: an attempt is recorded before its POST, so an
            // attempt without a definitive answer (worker killed mid-request, answer lost) is visible.
            $table->dropColumn('provider_said_busy');
            $table->unsignedSmallInteger('submission_attempts')->default(0)->after('submission_outcome_unknown');
            $table->unsignedSmallInteger('busy_refusals')->default(0)->after('submission_attempts');
            // The image could never be stored (not an image, too large, link refused): stop retrying.
            $table->boolean('image_unstorable')->default(false)->after('stored_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['submission_attempts', 'busy_refusals', 'image_unstorable']);
            $table->boolean('provider_said_busy')->default(false)->after('submission_outcome_unknown');
        });
    }
};
