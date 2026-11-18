<?php

namespace App\Http\Requests\Generations;

use Illuminate\Contracts\Validation\ValidationRule;
use Ramsey\Uuid\Uuid;

/**
 * The generate form's submit: the request itself plus the id the form generated for this attempt,
 * which makes a double click or a retried request start only one generation.
 */
class StoreGenerationRequest extends EstimateGenerationCostRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'client_request_id' => ['required', 'uuid'],
        ];
    }

    /**
     * One id per image: the form's id for the first, and ids derived from it (UUIDv5) for the rest,
     * so resubmitting the same form maps every image back to the generation it already started.
     *
     * @return list<string>
     */
    public function clientRequestIds(): array
    {
        $formId = $this->clientRequestId();
        $ids = [$formId];

        for ($image = 2; $image <= $this->imageCount(); $image++) {
            $ids[] = Uuid::uuid5($formId, 'image-'.$image)->toString();
        }

        return $ids;
    }

    public function clientRequestId(): string
    {
        return strtolower($this->string('client_request_id')->toString());
    }
}
