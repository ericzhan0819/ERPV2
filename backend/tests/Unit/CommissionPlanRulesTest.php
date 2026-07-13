<?php

namespace Tests\Unit;

use App\Support\CommissionPlanRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommissionPlanRulesTest extends TestCase
{
    public function test_valid_standard_tiers_pass(): void
    {
        CommissionPlanRules::validate(4000, 2000, [
            ['min_sales_count' => 1, 'sales_bonus_bps' => 2000, 'sort_order' => 1],
            ['min_sales_count' => 3, 'sales_bonus_bps' => 3000, 'sort_order' => 2],
            ['min_sales_count' => 5, 'sales_bonus_bps' => 5000, 'sort_order' => 3],
        ]);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidPlans')]
    public function test_invalid_tier_sets_are_rejected(int $reserve, int $purchase, array $tiers): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommissionPlanRules::validate($reserve, $purchase, $tiers);
    }

    public static function invalidPlans(): array
    {
        return [
            'empty tiers' => [4000, 2000, []],
            'first tier not one' => [4000, 2000, [['min_sales_count' => 2, 'sales_bonus_bps' => 2000, 'sort_order' => 1]]],
            'tiers not increasing' => [4000, 2000, [
                ['min_sales_count' => 1, 'sales_bonus_bps' => 2000, 'sort_order' => 1],
                ['min_sales_count' => 1, 'sales_bonus_bps' => 3000, 'sort_order' => 2],
            ]],
            'over allocated pool' => [4000, 6000, [['min_sales_count' => 1, 'sales_bonus_bps' => 5000, 'sort_order' => 1]]],
            'bps over range' => [10001, 2000, [['min_sales_count' => 1, 'sales_bonus_bps' => 2000, 'sort_order' => 1]]],
            'purchase bps over range' => [4000, 10001, [['min_sales_count' => 1, 'sales_bonus_bps' => 0, 'sort_order' => 1]]],
            'sales bps over range' => [4000, 0, [['min_sales_count' => 1, 'sales_bonus_bps' => 10001, 'sort_order' => 1]]],
        ];
    }
}
