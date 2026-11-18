<?php

namespace App\Http\Controllers\Admin;

use App\Application\Data\ProviderDailyUsage;
use App\Application\Exceptions\MalformedProviderPayload;
use App\Application\Exceptions\ProviderRefused;
use App\Application\Exceptions\ProviderUnavailable;
use App\Application\Ports\ImageGenerationProvider;
use App\Domain\Billing\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SpendReportRequest;
use App\Infrastructure\ReadModels\AdminSpendReportQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operators' spend and profit report (admin role only). Pixelforge's own figures load as a deferred
 * prop, so the report shows at once and a slow or unavailable Pixelforge never blocks it.
 */
class SpendReportController extends Controller
{
    /** Pixelforge's daily usage changes only as generations complete; one call a minute is plenty. */
    private const int USAGE_CACHE_SECONDS = 60;

    public function __invoke(SpendReportRequest $request, AdminSpendReportQuery $report, ImageGenerationProvider $provider): Response
    {
        [$from, $to] = $request->range();
        $data = $report->between($from, $to);

        return Inertia::render('admin/SpendReport', [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'max_days' => SpendReportRequest::MAX_DAYS],
            'report' => $data,
            'pixelforge' => Inertia::defer(function () use ($from, $to, $data, $report, $provider): array {
                try {
                    // Cached as plain values: the cache refuses to unserialize objects
                    // (cache.serializable_classes), which would hand back broken ones.
                    /** @var list<array{date: string, generations: int, cost_nanos: int}> $cached */
                    $cached = Cache::remember(
                        "pixelforge-usage:{$from->toDateString()}:{$to->toDateString()}",
                        self::USAGE_CACHE_SECONDS,
                        fn (): array => array_map(fn (ProviderDailyUsage $day): array => [
                            'date' => $day->date,
                            'generations' => $day->generations,
                            'cost_nanos' => $day->cost->nanos,
                        ], $provider->dailyUsage($from->toDateString(), $to->toDateString())),
                    );
                    $usage = array_map(
                        fn (array $day): ProviderDailyUsage => new ProviderDailyUsage($day['date'], $day['generations'], Money::fromNanos($day['cost_nanos'])),
                        $cached,
                    );
                } catch (ProviderUnavailable|ProviderRefused|MalformedProviderPayload $exception) {
                    Log::warning('Pixelforge usage unavailable for the admin report', ['error' => $exception->getMessage()]);

                    return ['available' => false, 'message' => "Couldn't reach Pixelforge to compare. Our figures above are complete; try again in a minute."];
                }

                return ['available' => true, ...$report->compareWithProvider($data['days'], $usage)];
            }),
        ]);
    }
}
