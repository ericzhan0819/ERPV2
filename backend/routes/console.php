<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// VehiclePhotoService::deletePhoto() 對外回報成功後才在背景嘗試 storage 清理；
// 失敗時只留下 soft-deleted tombstone row，且該 row 因為 SoftDeletes 從此不會
// 出現在一般查詢或 route model binding，使用者端沒有任何方式能再次觸發清理
// （Codex adversarial review 指出：只有一個手動指令，沒有排程/佇列會自動重試，
// 暫時性 storage 故障可能在無人發現前一直卡住）。這裡把既有的
// vehicle-photos:purge-trashed 指令排進排程，讓重試變成自動、不需要操作人員
// 手動介入才能觸發；仍保留手動執行的能力供緊急情況使用。
//
// 這個排程一旦上線就是無人值守執行，若沒有任何持久化輸出或失敗告警，指令印出
// 的 purged=/failed= 統計數字不會有人即時盯著看，storage 權限或連線真的長期
// 退化時，公開網址指向的檔案可能無限期留著卻完全沒人發現（Codex adversarial
// review 指出）。appendOutputTo 把每次執行結果持久化成獨立 log 檔，onFailure
// 在指令回傳非 0（purgeTrashedPhotos() 有任何一筆失敗）時額外寫一筆 error
// level 記錄，方便串接既有的 log 監控/告警管道；個別照片的詳細失敗原因見
// VehiclePhotoService::purgeTrashedPhotos() 內的 Log::warning()。
Schedule::command('vehicle-photos:purge-trashed')
    ->hourly()
    ->appendOutputTo(storage_path('logs/vehicle-photos-purge.log'))
    ->onFailure(function () {
        Log::error('vehicle-photos:purge-trashed 排程執行後仍有車輛照片 tombstone 未清乾淨，請查看 storage/logs/vehicle-photos-purge.log 與 laravel.log 的詳細記錄');
    });

// upload_batch_pending_ttl_seconds 過期只代表「允許下一個真實請求續傳認領」一批
// 未完成的照片上傳，不保證真的會有請求回來——如果使用者放棄重試，這批上傳裡已經
// 真的建立好的照片會一直以正常、可見的 VehiclePhoto row 留著，讓一次從未真正完成
// 的上傳看起來像是成功上傳了一部分，持續佔用每台車 60 張上限（Codex adversarial
// review 第八輪指出：先前版本只處理了「復原 transaction 本身失敗」時的錯誤回報，
// 沒有處理「沒有人再打進來重試」這個更根本的情境）。這裡把
// vehicle-photos:sweep-stale-uploads 指令排進排程，主動清理長期無人續傳、已被
// 永久放棄的批次，不需要等一個可能永遠不會出現的重試請求。
//
// 判斷「永久放棄」的門檻（upload_batch_abandon_sweep_seconds，預設 24 小時）遠大於
// 一般重試的合理時間窗，所以排程本身用 daily() 已經足夠及時，不需要跟
// purge-trashed 一樣抓到每小時；沿用同一套「輸出寫進獨立 log 檔 + 失敗時額外告警」
// 的既有模式，方便串接既有的 log 監控/告警管道。
Schedule::command('vehicle-photos:sweep-stale-uploads')
    ->daily()
    ->appendOutputTo(storage_path('logs/vehicle-photos-sweep-stale-uploads.log'))
    ->onFailure(function () {
        Log::error('vehicle-photos:sweep-stale-uploads 排程執行後仍有殘留的車輛照片上傳批次清理失敗，請查看 storage/logs/vehicle-photos-sweep-stale-uploads.log 與 laravel.log 的詳細記錄');
    });
