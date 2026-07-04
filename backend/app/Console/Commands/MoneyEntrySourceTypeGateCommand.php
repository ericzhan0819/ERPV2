<?php

namespace App\Console\Commands;

use App\Services\MoneyEntrySourceTypeReviewService;
use Illuminate\Console\Command;

/**
 * 部署 gate：
 * 1. 若存在 legacy_unknown 的既有收支，代表資料還沒人工確認完成。
 * 2. 若存在尚未 resolved 的 money_entry_source_type_review_candidates，代表
 *    這批 migration 執行當下拍照下來、可能被舊版不安全 backfill 誤標成
 *    vehicle_shortcut / vehicle_workflow 的既有收支還沒人工明確 review。
 * 兩者任一存在都卡住部署/交付，直到全部用 money-entries:source-type-review
 * 確認完畢。
 */
class MoneyEntrySourceTypeGateCommand extends Command
{
    protected $signature = 'money-entries:source-type-gate';

    protected $description = '檢查是否還有未人工確認的 legacy_unknown 收支或未解決的 review candidate，有則失敗擋住部署';

    public function handle(MoneyEntrySourceTypeReviewService $service): int
    {
        $result = $service->gate();

        if ($result['passed']) {
            $this->info('沒有待確認的 legacy_unknown 收支，也沒有未解決的 review candidate，gate 通過。');

            return self::SUCCESS;
        }

        if ($result['legacy_unknown_count'] > 0) {
            $this->error("發現 {$result['legacy_unknown_count']} 筆 legacy_unknown 收支尚未人工確認。");
        }

        if ($result['unresolved_candidate_count'] > 0) {
            $this->error("發現 {$result['unresolved_candidate_count']} 筆待人工 review 的 candidate（可能是舊版不安全 backfill 誤標的 vehicle_shortcut / vehicle_workflow，或既有 legacy_unknown）。");
        }

        $this->line('必須先用 money-entries:source-type-review 處理，樣本如下：');

        foreach ($result['sample'] as $row) {
            $this->line(sprintf(
                'id=%d category=%s amount=%s vehicle_id=%s current_source_type=%s captured_source_type=%s candidate_reason=%s',
                $row['id'],
                $row['category'] ?? 'null',
                $row['amount'] === null ? 'null' : (string) $row['amount'],
                $row['vehicle_id'] === null ? 'null' : (string) $row['vehicle_id'],
                $row['current_source_type'] ?? 'null',
                $row['captured_source_type'],
                $row['candidate_reason']
            ));
        }

        return self::FAILURE;
    }
}
