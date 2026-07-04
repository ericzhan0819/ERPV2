<?php

namespace App\Console\Commands;

use App\Services\MoneyEntrySourceTypeReviewService;
use Illuminate\Console\Command;

/**
 * 部署 gate：若存在 legacy_unknown 的既有收支，代表資料還沒人工確認完成，
 * 卡住部署/交付，直到全部用 money-entries:source-type-review 確認完畢。
 */
class MoneyEntrySourceTypeGateCommand extends Command
{
    protected $signature = 'money-entries:source-type-gate';

    protected $description = '檢查是否還有未人工確認的 legacy_unknown 收支，有則失敗擋住部署';

    public function handle(MoneyEntrySourceTypeReviewService $service): int
    {
        $result = $service->gate();

        if ($result['count'] === 0) {
            $this->info('沒有待確認的 legacy_unknown 收支，gate 通過。');

            return self::SUCCESS;
        }

        $this->error("發現 {$result['count']} 筆 legacy_unknown 收支尚未人工確認，必須先用 money-entries:source-type-review 處理：");

        foreach ($result['sample'] as $row) {
            $this->line(sprintf(
                'id=%d category=%s amount=%d vehicle_id=%s source_type=%s',
                $row['id'],
                $row['category'],
                $row['amount'],
                $row['vehicle_id'] === null ? 'null' : (string) $row['vehicle_id'],
                $row['source_type']
            ));
        }

        return self::FAILURE;
    }
}
