<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Codex adversarial review（第三輪）指出：前一版的「超過 TTL 就整批放棄並回收」設計
// 對「前一次處理程序已經成功建立一部分照片、只是還沒處理完全部檔案就被中止」這種
// 部分完成的批次不安全——回收後會把整批檔案（包含已經真的建立過照片的那幾個）
// 全部重新處理一次，等於系統性地重複建立照片，而不是單純的罕見殘留風險。
//
// 這裡加上 processing_lease_expires_at：把「這批上傳目前是否有人正在處理」從
// 「reservation row 建立的時間點（created_at）」改成「目前這次認領（claim）核發的
// 租約到期時間」，並讓 vehicle_photos.id 隨著每個檔案處理完成，在同一個 transaction
// 內逐一寫回 photo_ids（見 VehiclePhotoService::uploadPhotos()），而不是等整批都做完
// 才一次寫入。同一把 idempotency_key 之後不管是回放、續傳未完成的部分，還是在租約
// 過期後重新認領，都能依照 photo_ids 目前實際記錄的進度，只處理「還沒做的那幾個
// 檔案」，不重做已經成功的部分。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_photo_upload_batches', function (Blueprint $table) {
            $table->timestamp('processing_lease_expires_at')->nullable()->after('photo_ids');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_photo_upload_batches', function (Blueprint $table) {
            $table->dropColumn('processing_lease_expires_at');
        });
    }
};
