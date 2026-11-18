<?php

namespace App\Http\Controllers;

use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\UseCases\EstimateGenerationCost;
use App\Http\Requests\Generations\EstimateGenerationCostRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Prices a generation before it is submitted, with the same formula used when it is started, so
 * the price the form shows is the price that gets stored.
 */
class GenerationEstimateController extends Controller
{
    public const string PRICING_UNAVAILABLE = 'We can\'t get Pixelforge\'s prices right now, so we can\'t show a price. Try again in a moment.';

    public function __invoke(EstimateGenerationCostRequest $request, EstimateGenerationCost $estimateCost): JsonResponse
    {
        try {
            $priced = $estimateCost->handle($request->toGenerationRequest(), $request->imageCount());
        } catch (ProviderUnavailable|MalformedProviderPayload|ProviderRefused $unavailable) {
            Log::warning('Pixelforge pricing unavailable for an estimate', ['exception' => $unavailable->getMessage()]);

            return response()->json(['message' => self::PRICING_UNAVAILABLE], 503);
        }

        return response()->json([
            // Each image is its own generation with the same estimate: these are totals.
            'count' => $priced->imageCount,
            'min_nanos' => (string) $priced->minimumCharge->nanos,
            'max_nanos' => (string) $priced->maximumCharge->nanos,
            // Rounded outward to cents so the range shown always contains the real charge.
            'min_display' => $priced->minimumCharge->floorToCents()->toDisplayString(),
            'max_display' => $priced->maximumCharge->ceilToCents()->toDisplayString(),
            // Every prompt length in this range shows exactly this price, so the form doesn't ask again
            // while the user types within it.
            'same_price_for_characters' => [
                'from' => $priced->samePriceFromCharacters,
                'to' => $priced->samePriceToCharacters,
            ],
        ]);
    }
}
