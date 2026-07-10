<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Codex adversarial review 指出：vehicle_photos row 在 uploadPhotos() 逐檔處理時
// 一建立就立刻對外可見（一般列表、public API、setCover、reorder 都透過
// Vehicle::photos() 這個沒有任何過濾條件的關聯查到它們），但這批上傳整體是否
// 「真的完成」要等所有檔案都處理完才知道。如果整批上傳中途永久放棄（使用者關
// 分頁、worker 當機），vehicle-photos:sweep-stale-uploads 排程稍後會依
// VehiclePhotoUploadBatch.photo_ids 把這些照片 soft-delete——但這些照片這段
// 期間可能早已經被使用者看到、設成封面、排進相簿順序，此時才刪除等於在使用者
// 不知情的狀況下，把已經生效過的資料憑空拿走。
//
// 加上 upload_batch_id：照片在批次逐檔建立當下記錄它屬於哪個 batch（此時視為
// 「尚未提交，暫不可見」）；整批上傳全部成功後，一次性把這批照片的
// upload_batch_id 清成 NULL，才視為「已提交、正式可見」（見
// VehiclePhotoService::uploadPhotos() 收尾與 VehiclePhoto::scopeVisible()）。
// 任何未完成就被 sweep 掉的批次，其照片的 upload_batch_id 必然還沒被清空，
// 代表這些照片從頭到尾都不曾透過一般列表、public API 或封面/排序操作對外
// 曝光過，sweep 掉它們不會造成使用者可觀察到的資料消失。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_photos', function (Blueprint $table) {
            $table->foreignId('upload_batch_id')
                ->nullable()
                ->after('vehicle_id')
                ->constrained('vehicle_photo_upload_batches')
                ->nullOnDelete();

            $table->index(['vehicle_id', 'upload_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_photos', function (Blueprint $table) {
            $table->dropIndex(['vehicle_id', 'upload_batch_id']);
            $table->dropConstrainedForeignId('upload_batch_id');
        });
    }
};
