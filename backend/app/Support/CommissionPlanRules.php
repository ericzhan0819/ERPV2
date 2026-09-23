<?php

namespace App\Support;

use InvalidArgumentException;

final class CommissionPlanRules
{
    /**
     * @param  array<int, array{min_sales_count: int, sales_bonus_bps: int, sort_order: int}>  $tiers
     */
    public static function validate(int $companyReserveBps, int $purchaseBonusBps, array $tiers): void
    {
        self::assertBasisPoints('company_reserve_bps', $companyReserveBps);
        self::assertBasisPoints('purchase_bonus_bps', $purchaseBonusBps);

        if ($tiers === []) {
            throw new InvalidArgumentException('獎金方案至少需要一個賣車級距');
        }

        $previousMin = null;
        $seenSortOrders = [];

        foreach ($tiers as $index => $tier) {
            $minSalesCount = (int) $tier['min_sales_count'];
            $salesBonusBps = (int) $tier['sales_bonus_bps'];
            $sortOrder = (int) $tier['sort_order'];

            if ($index === 0 && $minSalesCount !== 1) {
                throw new InvalidArgumentException('第一個賣車級距必須從 1 台開始');
            }

            if ($previousMin !== null && $minSalesCount <= $previousMin) {
                throw new InvalidArgumentException("tiers.{$index}.min_sales_count：賣車級距台數必須依序遞增且不可重複");
            }

            if ($sortOrder < 1 || in_array($sortOrder, $seenSortOrders, true)) {
                throw new InvalidArgumentException("tiers.{$index}.sort_order：級距排序必須為不重複的正整數");
            }

            self::assertBasisPoints("tiers.{$index}.sales_bonus_bps", $salesBonusBps);

            if ($purchaseBonusBps + $salesBonusBps > 10000) {
                throw new InvalidArgumentException("tiers.{$index}.sales_bonus_bps：收車獎金與賣車獎金合計不得超過分配池 100%");
            }

            $previousMin = $minSalesCount;
            $seenSortOrders[] = $sortOrder;
        }
    }

    private static function assertBasisPoints(string $field, int $value): void
    {
        if ($value < 0 || $value > 10000) {
            throw new InvalidArgumentException("{$field}：比例必須介於 0 到 10000 basis points");
        }
    }
}
