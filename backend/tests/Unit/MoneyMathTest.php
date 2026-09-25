<?php

namespace Tests\Unit;

use App\Support\MoneyMath;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MoneyMathTest extends TestCase
{
    public function test_decimal_database_totals_are_validated_before_integer_conversion(): void
    {
        $this->assertSame(PHP_INT_MAX, MoneyMath::integer((string) PHP_INT_MAX));
        $this->assertSame(PHP_INT_MIN, MoneyMath::integer((string) PHP_INT_MIN));
        foreach (['9223372036854775808', '-9223372036854775809', 1.0] as $value) {
            try {
                MoneyMath::integer($value);
                $this->fail('Unsafe totals must not be truncated or converted from floats');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('amount', $exception->errors());
            }
        }
    }

    public function test_signed_financial_arithmetic_checks_both_integer_boundaries(): void
    {
        $this->assertSame(0, MoneyMath::add(PHP_INT_MAX, -PHP_INT_MAX));
        $this->assertSame(PHP_INT_MIN, MoneyMath::subtract(-1, PHP_INT_MAX));
        foreach ([
            fn () => MoneyMath::add(PHP_INT_MAX, 1),
            fn () => MoneyMath::add(PHP_INT_MIN, -1),
            fn () => MoneyMath::subtract(PHP_INT_MIN, 1),
            fn () => MoneyMath::subtract(PHP_INT_MAX, -1),
        ] as $calculation) {
            try {
                $calculation();
                $this->fail('Overflow must not return a float or a truncated integer');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('amount', $exception->errors());
            }
        }
    }
}
