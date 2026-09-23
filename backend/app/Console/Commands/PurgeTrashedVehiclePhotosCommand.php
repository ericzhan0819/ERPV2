<?php

namespace App\Console\Commands;

use App\Services\VehiclePhotoService;
use Illuminate\Console\Command;

/**
 * 掃描並重試因 storage 清理失敗（或處理中途中斷）而卡在 soft-deleted tombstone
 * 狀態的車輛照片。VehiclePhotoService::deletePhoto() 只在 DB commit 後嘗試一次
 * storage 清理，失敗時完全不會自動重試（見 VehiclePhotoService::purgeTrashedPhotos()
 * 的說明）。這個指令已在 routes/console.php 排進每小時排程自動執行，不需要操作
 * 人員發現 log 後才手動介入；仍保留手動執行的能力供緊急情況立即重試使用。
 */
class PurgeTrashedVehiclePhotosCommand extends Command
{
    protected $signature = 'vehicle-photos:purge-trashed';

    protected $description = '重試清理因 storage 刪除失敗而卡住的已刪除車輛照片';

    public function handle(VehiclePhotoService $service): int
    {
        $result = $service->purgeTrashedPhotos();

        $this->line("purged={$result['purged']} failed={$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
