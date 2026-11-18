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
     * One id per generate-form submission. The unique index makes a double click or a retried
     * request map to the generation it already started, so it is never submitted or billed twice.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->uuid('client_request_id')->nullable();
        });

        // Generations created before this column existed each count as their own submission.
        DB::statement('UPDATE generations SET client_request_id = id WHERE client_request_id IS NULL');
        DB::statement('ALTER TABLE generations ALTER COLUMN client_request_id SET NOT NULL');

        Schema::table('generations', function (Blueprint $table) {
            $table->unique(['user_id', 'client_request_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_request_id']);
            $table->dropColumn('client_request_id');
        });
    }
};
