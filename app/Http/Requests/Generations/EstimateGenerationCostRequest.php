<?php

namespace App\Http\Requests\Generations;

use App\Application\UseCases\StartGenerationBatch;
use App\Domain\Generation\GenerationRequest;
use App\Domain\Generation\ImageSize;
use App\Domain\Generation\InvalidGenerationRequest;
use App\Domain\Generation\Quality;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * What the generate form sends, validated with the same limits Pixelforge enforces. The domain's
 * GenerationRequest has the final say, so a gap between these rules and the domain becomes a form
 * error instead of a server error.
 */
class EstimateGenerationCostRequest extends FormRequest
{
    private ?GenerationRequest $generationRequest = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.GenerationRequest::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_image_urls' => ['sometimes', 'array', 'max:'.GenerationRequest::MAX_REFERENCE_IMAGES],
            'reference_image_urls.*' => ['required', 'string', 'max:2048', 'url:http,https'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:'.StartGenerationBatch::MAX_IMAGES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Describe the image you want.',
            'prompt.max' => 'The prompt can be at most '.number_format(GenerationRequest::MAX_PROMPT_CHARACTERS).' characters.',
            'reference_image_urls.max' => 'Add at most '.GenerationRequest::MAX_REFERENCE_IMAGES.' reference images.',
            'reference_image_urls.*.required' => 'Enter a link or remove this reference image.',
            'reference_image_urls.*.url' => 'Reference images must be http(s) links.',
            'reference_image_urls.*.max' => 'This link is too long.',
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $this->generationRequest = new GenerationRequest(
                        $this->string('prompt')->toString(),
                        $this->enum('size', ImageSize::class) ?? throw new LogicException('size was validated'),
                        $this->enum('quality', Quality::class) ?? throw new LogicException('quality was validated'),
                        array_values(array_map(strval(...), (array) $this->input('reference_image_urls', []))),
                    );
                } catch (InvalidGenerationRequest $invalid) {
                    $validator->errors()->add('prompt', $invalid->getMessage());
                }
            },
        ];
    }

    /**
     * How many images to make from this request (each is a separate Pixelforge generation).
     */
    public function imageCount(): int
    {
        return $this->integer('count', 1);
    }

    public function toGenerationRequest(): GenerationRequest
    {
        return $this->generationRequest ?? throw new LogicException('The request has not been validated.');
    }
}
