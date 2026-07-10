<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
Schedule::command('vehicle-photos:purge-trashed')->hourly();
