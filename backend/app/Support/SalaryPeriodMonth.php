<?php

namespace App\Support;

use InvalidArgumentException;

final class SalaryPeriodMonth
{
    public static function normalize(string $periodMonth): string
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodMonth) !== 1) {
            throw new InvalidArgumentException('結算月份必須使用 YYYY-MM 格式');
        }

        return $periodMonth;
    }

    public static function firstDay(string $periodMonth): string
    {
        return self::normalize($periodMonth).'-01';
    }
}
