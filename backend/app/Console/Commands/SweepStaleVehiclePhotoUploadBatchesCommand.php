<?php

namespace App\Console\Commands;

use App\Services\VehiclePhotoService;
use Illuminate\Console\Command;

/**
 * 掃描並清理長期沒有任何請求再回來續傳的未完成車輛照片上傳批次。
 * upload_batch_pending_ttl_seconds 過期只代表「允許下一個真實請求續傳認領」，
 * 不保證真的會有請求回來；如果使用者放棄重試，這批上傳裡已經真的建立好的照片
 * 會一直以正常、可見的 VehiclePhoto row 留著（Codex adversarial review 第八輪
 * 指出）。這個指令已在 routes/console.php 排進排程自動執行，不需要操作人員發現
 * 才手動介入；仍保留手動執行的能力供緊急情況立即清理使用。
 */
class SweepStaleVehiclePhotoUploadBatchesCommand extends Command
{
    protected $signature = 'vehicle-photos:sweep-stale-uploads';

    protected $description = '清理長期無人續傳、已被永久放棄的車輛照片上傳批次殘留照片';

    public function handle(VehiclePhotoService $service): int
    {
        $result = $service->abandonStaleIncompleteUploadBatches();

        $this->line("abandoned={$result['abandoned']} failed={$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
