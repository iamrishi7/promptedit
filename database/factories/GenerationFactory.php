<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Amounts follow Pixelforge's documented rates: a high quality 1536x1024 generation with one
 * reference image and a 45-character prompt.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_request_id' => fake()->uuid(),
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_image_urls' => ['https://example.com/style.png'],
            'markup_percent' => 25,
            'status' => 'pending',
            'provider_id' => null,
            'estimated_min_provider_cost_nanos' => 278_200_000,
            'estimated_max_provider_cost_nanos' => 365_720_000,
            'estimated_min_charge_nanos' => 347_750_000,
            'estimated_max_charge_nanos' => 457_150_000,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => 'queued',
            'provider_id' => 'gen_'.fake()->unique()->regexify('[A-Z0-9]{26}'),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn () => ['status' => 'processing']);
    }

    public function succeeded(): static
    {
        return $this->queued()->state(fn () => [
            'status' => 'succeeded',
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'provider_cost_nanos' => 305_510_000,
            'charge_nanos' => 381_887_500,
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'image_url_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Failed before Pixelforge accepted it (rejected prompt or abandoned submission): no Pixelforge id.
     */
    public function failedLocally(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'provider_cost_nanos' => 0,
            'charge_nanos' => 0,
            'failure_code' => 'content_policy_violation',
            'failure_message' => 'The prompt was rejected by the content policy.',
        ]);
    }

    public function failed(): static
    {
        return $this->queued()->state(fn () => [
            'status' => 'failed',
            'provider_cost_nanos' => 0,
            'charge_nanos' => 0,
            'failure_code' => 'generation_failed',
            'failure_message' => 'The model could not produce an image for this prompt.',
            'completed_at' => now(),
        ]);
    }
}
