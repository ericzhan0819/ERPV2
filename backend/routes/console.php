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
