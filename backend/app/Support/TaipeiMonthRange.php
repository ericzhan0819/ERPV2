<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class TaipeiMonthRange
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function fromYearMonth(string $yearMonth): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth, $matches)) {
            throw new InvalidArgumentException('月份格式必須為 YYYY-MM');
        }

        [$year, $month] = array_map('intval', explode('-', $yearMonth));
        $start = Carbon::create($year, $month, 1, 0, 0, 0, config('app.timezone'));

        return [$start, $start->copy()->addMonth()];
    }
}
