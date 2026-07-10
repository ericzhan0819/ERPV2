<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 新增 soft-delete 用的 deleted_at 欄位。
//
// VehiclePhotoService::deletePhoto() 需要把「DB 狀態變更」與「storage 實體刪除」
// 拆成兩步，因為兩者無法跨系統原子化。兩輪 Codex adversarial review 分別指出這兩種
// 排序各自的風險：
//   1. 先刪 DB row 再刪 storage：DB commit 後、storage 實際刪除完成前，仍有一段
//      時間視窗讓「已刪除」的照片可被直接用舊網址公開存取。
//   2. 先刪 storage 再刪 DB row：若處理過程中當機、逾時或部分刪除失敗，DB row
//      會永久卡在指向不存在檔案的壞掉狀態，且沒有任何機制可以重試。
//
// 加上 deleted_at 後，deletePhoto() 可以在鎖定車輛 row 的同一個 transaction 內把
// 照片標記為 soft-deleted（同時清空 is_cover、改指定下一張封面），一旦這個
// transaction commit，該照片立刻從所有查詢（含 public API）消失，不會再有「DB 說
// 已刪除、storage 還在服務」的公開存取視窗。commit 之後才進行 storage 實體刪除；
// 如果那一步失敗或程序中斷，DB row 仍完整保留 disk/path/thumbnail_path，不會遺失
// 「這個檔案還沒清乾淨」的紀錄，之後可以安全重試 storage 清理，而不會被誤判成
// 資料損毀。storage 清理成功後才會 forceDelete() 徹底移除這筆 tombstone row。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_photos', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_photos', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
