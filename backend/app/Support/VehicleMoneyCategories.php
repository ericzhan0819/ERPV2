<?php

namespace App\Support;

final class VehicleMoneyCategories
{
    public const SALES_COLLECTION_INCOME = ['訂金收入', '尾款收入'];

    public const SALES_REFUND = '退款';

    public const SALES_SAFE = [...self::SALES_COLLECTION_INCOME, self::SALES_REFUND];
}
