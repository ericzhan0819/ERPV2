<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class MoneyMath
{
    public const MAX_AMOUNT = 999999999999;

    public static function integer(mixed $value): int
    {
        $integer = (is_int($value) || is_string($value)) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($integer === false) {
            self::overflow();
        }

        return $integer;
    }

    /** @template T
     * @param  Closure(): T  $query
     * @return T
     */
    public static function aggregate(Closure $query): mixed
    {
        try {
            return $query();
        } catch (QueryException $exception) {
            // SQLite SUM throws before the result can be validated in PHP.
            if (! str_contains($exception->getMessage(), 'integer overflow')) {
                throw $exception;
            }
            self::overflow();
        }
    }

    public static function sum(Builder $query, string $column = 'amount'): int
    {
        return self::integer(self::aggregate(fn () => $query->sum($column)));
    }

    public static function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            self::overflow();
        }

        return $left + $right;
    }

    public static function subtract(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)) {
            self::overflow();
        }

        return $left - $right;
    }

    /** @param iterable<int> $values */
    public static function total(iterable $values): int
    {
        $total = 0;
        foreach ($values as $value) {
            $total = self::add($total, $value);
        }

        return $total;
    }

    private static function overflow(): never
    {
        throw ValidationException::withMessages(['amount' => ['財務彙總超出可安全計算的整數範圍']]);
    }
}
