<?php

declare(strict_types=1);

namespace App\Infrastructure\ReadModels;

use App\Application\Data\ProviderDailyUsage;
use App\Domain\Billing\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The operators' report: per UTC day and per user, what users were charged, what Pixelforge charged
 * us, and the profit.
 *
 * Only succeeded generations are billed, and a generation belongs to the UTC day it completed,
 * exactly how Pixelforge's GET /v1/usage counts. Our provider cost per day can therefore be
 * compared with Pixelforge's to the nanodollar. Money is summed in Postgres as exact integers and
 * leaves as nanodollar strings plus display text.
 */
final class AdminSpendReportQuery
{
    /**
     * @return array{
     *     days: list<array<string, mixed>>,
     *     totals: array<string, mixed>
     * }
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Walks the partial index generations_report (completed_at) INCLUDE (user_id, charge, cost).
        $rows = DB::table('generations')
            ->selectRaw('(completed_at)::date as day, user_id, count(*) as generations, sum(charge_nanos) as charge_nanos, sum(provider_cost_nanos) as provider_cost_nanos')
            ->whereRaw("status = 'succeeded'")
            ->where('completed_at', '>=', $from->startOfDay())
            ->where('completed_at', '<', $to->addDay()->startOfDay())
            ->groupByRaw('(completed_at)::date, user_id')
            ->get();

        $users = User::withTrashed()
            ->whereIn('id', $rows->pluck('user_id')->unique()->all())
            ->get(['id', 'name', 'email', 'deleted_at'])
            ->keyBy('id');

        $byDay = [];

        foreach ($rows as $row) {
            $user = $users->get($row->user_id);
            $byDay[(string) $row->day][] = [
                'user_id' => (int) $row->user_id,
                'name' => $user->name ?? 'Unknown user',
                'email' => $user?->trashed() ? null : $user?->email,
                'deleted' => $user?->trashed() ?? true,
                ...$this->amounts((int) $row->generations, Money::fromNanos((int) $row->charge_nanos), Money::fromNanos((int) $row->provider_cost_nanos)),
            ];
        }

        $days = [];
        $totalGenerations = 0;
        $totalCharge = Money::zero();
        $totalCost = Money::zero();

        // Every day in the range, newest first, including days with nothing billed (Pixelforge
        // lists those too, with zeros), so each day can be compared.
        for ($day = $to; $day >= $from; $day = $day->subDay()) {
            $date = $day->toDateString();
            $users = $byDay[$date] ?? [];
            usort($users, fn (array $a, array $b): int => (int) $b['charge']['nanos'] <=> (int) $a['charge']['nanos'] ?: $a['user_id'] <=> $b['user_id']);

            $generations = array_sum(array_column($users, 'generations'));
            $charge = Money::zero();
            $cost = Money::zero();

            foreach ($users as $user) {
                $charge = $charge->plus(Money::fromNanos((int) $user['charge']['nanos']));
                $cost = $cost->plus(Money::fromNanos((int) $user['provider_cost']['nanos']));
            }

            $days[] = ['date' => $date, ...$this->amounts($generations, $charge, $cost), 'users' => $users];
            $totalGenerations += $generations;
            $totalCharge = $totalCharge->plus($charge);
            $totalCost = $totalCost->plus($cost);
        }

        return [
            'days' => $days,
            'totals' => $this->amounts($totalGenerations, $totalCharge, $totalCost),
        ];
    }

    /**
     * Compare our provider cost per day with what Pixelforge billed this key.
     *
     * @param  list<array<string, mixed>>  $days  from between()
     * @param  list<ProviderDailyUsage>  $usage
     * @return array{days: array<string, array<string, mixed>>, matches: bool}
     */
    public function compareWithProvider(array $days, array $usage): array
    {
        $theirs = [];

        foreach ($usage as $day) {
            $theirs[$day->date] = $day;
        }

        $compared = [];
        $allMatch = true;

        foreach ($days as $day) {
            /** @var array{nanos: string} $ourCost */
            $ourCost = $day['provider_cost'];
            $ours = Money::fromNanos((int) $ourCost['nanos']);
            $their = $theirs[$day['date']] ?? null;

            if ($their === null) {
                $allMatch = false;
                $compared[$day['date']] = ['known' => false];

                continue;
            }

            $matches = $their->cost->equals($ours) && $their->generations === $day['generations'];
            $allMatch = $allMatch && $matches;

            $compared[$day['date']] = [
                'known' => true,
                'generations' => $their->generations,
                'cost' => $this->money($their->cost),
                'matches' => $matches,
                // How far our record is from Pixelforge's, as a positive amount and a direction.
                'difference' => $their->cost->equals($ours) ? null : [
                    'amount' => $this->money($their->cost->isGreaterThan($ours) ? $their->cost->minus($ours) : $ours->minus($their->cost)),
                    'we_recorded' => $their->cost->isGreaterThan($ours) ? 'less' : 'more',
                ],
            ];
        }

        return ['days' => $compared, 'matches' => $allMatch];
    }

    /**
     * @return array<string, mixed>
     */
    private function amounts(int $generations, Money $charge, Money $providerCost): array
    {
        return [
            'generations' => $generations,
            'charge' => $this->money($charge),
            'provider_cost' => $this->money($providerCost),
            // The charge is never below the cost (a database CHECK), so profit is never negative.
            'profit' => $this->money($profit = $charge->minus($providerCost)),
            // Profit as a share of what users paid (25% markup = 20% margin); null when nothing was charged.
            'margin' => $this->percent($profit->basisPointsOf($charge)),
        ];
    }

    /**
     * Basis points as text, exactly: 2000 → "20.00%".
     */
    private function percent(?int $basisPoints): ?string
    {
        if ($basisPoints === null) {
            return null;
        }

        $digits = str_pad((string) $basisPoints, 3, '0', STR_PAD_LEFT);

        return substr($digits, 0, -2).'.'.substr($digits, -2).'%';
    }

    /**
     * @return array{nanos: string, display: string}
     */
    private function money(Money $amount): array
    {
        return ['nanos' => (string) $amount->nanos, 'display' => $amount->toDisplayString()];
    }
}
