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
     * Money columns are big integers in nanodollars (1 USD = 1,000,000,000), never floats. Postgres has
     * no unsigned types, so CHECK constraints make the database itself enforce the billing invariants.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 8);
            $table->json('reference_image_urls');
            $table->unsignedSmallInteger('markup_percent');

            $table->string('status', 16);
            $table->string('provider_id')->nullable()->unique();
            $table->boolean('submission_outcome_unknown')->default(false);

            $table->unsignedBigInteger('estimated_min_provider_cost_nanos');
            $table->unsignedBigInteger('estimated_max_provider_cost_nanos');
            $table->unsignedBigInteger('estimated_min_charge_nanos');
            $table->unsignedBigInteger('estimated_max_charge_nanos');

            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedBigInteger('provider_cost_nanos')->nullable();
            $table->unsignedBigInteger('charge_nanos')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE generations
                ADD CONSTRAINT generations_status_valid
                    CHECK (status IN ('pending', 'queued', 'processing', 'succeeded', 'failed')),
                ADD CONSTRAINT generations_markup_valid CHECK (markup_percent >= 0),
                ADD CONSTRAINT generations_estimates_valid CHECK (
                    estimated_min_provider_cost_nanos >= 0 AND estimated_min_charge_nanos >= 0
                    AND estimated_min_provider_cost_nanos <= estimated_max_provider_cost_nanos
                    AND estimated_min_charge_nanos <= estimated_max_charge_nanos),
                ADD CONSTRAINT generations_cost_non_negative
                    CHECK (provider_cost_nanos >= 0 AND charge_nanos >= provider_cost_nanos),
                ADD CONSTRAINT generations_tokens_non_negative CHECK (
                    input_text_tokens >= 0 AND input_image_tokens >= 0 AND output_tokens >= 0 AND reasoning_tokens >= 0),
                ADD CONSTRAINT generations_usage_all_or_none
                    CHECK (num_nulls(input_text_tokens, input_image_tokens, output_tokens, reasoning_tokens) IN (0, 4)),
                ADD CONSTRAINT generations_cost_iff_terminal CHECK (
                    (status IN ('succeeded', 'failed')) = (provider_cost_nanos IS NOT NULL AND charge_nanos IS NOT NULL)
                    AND (provider_cost_nanos IS NULL) = (charge_nanos IS NULL)),
                ADD CONSTRAINT generations_failed_is_free
                    CHECK (status <> 'failed' OR (provider_cost_nanos = 0 AND charge_nanos = 0)),
                ADD CONSTRAINT generations_succeeded_complete CHECK (
                    status <> 'succeeded' OR (provider_id IS NOT NULL AND input_text_tokens IS NOT NULL AND completed_at IS NOT NULL)),
                ADD CONSTRAINT generations_accepted_has_provider_id
                    CHECK (status IN ('pending', 'failed') OR provider_id IS NOT NULL)
            SQL);

        // Poller: in-flight generations, least recently polled first (partial index stays tiny).
        DB::statement("CREATE INDEX generations_pollable ON generations (last_polled_at NULLS FIRST) WHERE status IN ('queued', 'processing')");

        // Admin report: succeeded generations per UTC completion day, answered from the index alone.
        DB::statement("CREATE INDEX generations_report ON generations (completed_at) INCLUDE (user_id, charge_nanos, provider_cost_nanos) WHERE status = 'succeeded'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
