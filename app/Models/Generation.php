<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistence record for a generation. Business rules live in App\Domain\Generation\Generation;
 * this model is only touched by infrastructure adapters and read models.
 *
 * @property string $id
 * @property int $user_id
 * @property string $client_request_id
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_image_urls
 * @property int $markup_percent
 * @property string $status
 * @property string|null $provider_id
 * @property bool $submission_outcome_unknown
 * @property int $submission_attempts
 * @property int $busy_refusals
 * @property int $estimated_min_provider_cost_nanos
 * @property int $estimated_max_provider_cost_nanos
 * @property int $estimated_min_charge_nanos
 * @property int $estimated_max_charge_nanos
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $provider_cost_nanos
 * @property int|null $charge_nanos
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $stored_image_path
 * @property bool $image_unstorable
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $last_polled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'id', 'user_id', 'client_request_id', 'prompt', 'size', 'quality', 'reference_image_urls', 'markup_percent', 'status', 'provider_id',
    'submission_outcome_unknown', 'submission_attempts', 'busy_refusals', 'stored_image_path', 'image_unstorable',
    'estimated_min_provider_cost_nanos', 'estimated_max_provider_cost_nanos',
    'estimated_min_charge_nanos', 'estimated_max_charge_nanos',
    'input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens',
    'provider_cost_nanos', 'charge_nanos', 'image_url', 'image_url_expires_at',
    'failure_code', 'failure_message', 'completed_at', 'last_polled_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_image_urls' => 'array',
            'markup_percent' => 'integer',
            'submission_outcome_unknown' => 'boolean',
            'submission_attempts' => 'integer',
            'busy_refusals' => 'integer',
            'image_unstorable' => 'boolean',
            'estimated_min_provider_cost_nanos' => 'integer',
            'estimated_max_provider_cost_nanos' => 'integer',
            'estimated_min_charge_nanos' => 'integer',
            'estimated_max_charge_nanos' => 'integer',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charge_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_polled_at' => 'datetime',
        ];
    }
}
