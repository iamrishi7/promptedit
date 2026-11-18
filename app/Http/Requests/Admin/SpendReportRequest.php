<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The report's date range: UTC days, inclusive, at most 92 of them (Pixelforge's /v1/usage limit,
 * so every day shown can be compared). Defaults to the last 14 days.
 */
class SpendReportRequest extends FormRequest
{
    public const int MAX_DAYS = 92;

    private const int DEFAULT_DAYS = 14;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->range();

            if ($from > $to) {
                $validator->errors()->add('from', 'The start date must be on or before the end date.');
            } elseif ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('from', 'Choose at most '.self::MAX_DAYS.' days.');
            } elseif ($to > CarbonImmutable::today('UTC')) {
                $validator->errors()->add('to', 'The end date can\'t be in the future.');
            }
        }];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $today = CarbonImmutable::today('UTC');
        $to = $this->filled('to') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->string('to')->toString(), 'UTC') : $today;
        $from = $this->filled('from') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->string('from')->toString(), 'UTC') : $to->subDays(self::DEFAULT_DAYS - 1);

        return [$from ?: $today, $to ?: $today];
    }
}
