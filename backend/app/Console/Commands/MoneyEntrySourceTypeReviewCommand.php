<?php

namespace App\Console\Commands;

use App\Services\MoneyEntrySourceTypeReviewService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * 人工確認既有收支來源後，針對明確 ID 清單改 source_type。
 * 不允許用 category/status/buyer_name/sold_price 等條件批次判斷。
 */
class MoneyEntrySourceTypeReviewCommand extends Command
{
    protected $signature = 'money-entries:source-type-review
        {--ids= : 逗號或空白分隔的 money_entry id 清單}
        {--file= : 內含 id 清單的檔案路徑}
        {--to= : 目標 source_type（manual/vehicle_shortcut/vehicle_workflow/legacy_unknown）}
        {--approver= : 人工確認人，非 dry-run 時必填}
        {--reason= : 確認理由，非 dry-run 時必填}
        {--dry-run : 僅預覽，不寫入資料庫}';

    protected $description = '人工確認後，針對明確 money_entry id 清單修改 source_type';

    public function handle(MoneyEntrySourceTypeReviewService $service): int
    {
        $to = (string) $this->option('to');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $ids = $service->parseIds($this->option('ids'), $this->option('file'));

            $result = $service->review(
                $ids,
                $to,
                $this->option('approver'),
                $this->option('reason'),
                $dryRun
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($dryRun ? '[dry-run] 以下為預覽結果，未寫入資料庫：' : '執行結果：');

        foreach ($result['rows'] as $row) {
            $this->line(sprintf(
                'id=%d %s -> %s (%s)',
                $row['id'],
                $row['previous_source_type'],
                $row['new_source_type'],
                $row['action']
            ));
        }

        $this->line(sprintf(
            'total=%d changed=%d skipped=%d',
            $result['total'],
            $result['changed'],
            $result['skipped']
        ));

        if (! empty($result['backup_path'])) {
            $this->line("backup: {$result['backup_path']}");
        }

        return self::SUCCESS;
    }
}
