<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 車輛照片上傳（POST /api/vehicles/{vehicle}/photos）先前沒有 idempotency 保護：
// 每次呼叫都直接建立新的 storage 檔案與 vehicle_photos row（Codex adversarial review
// 指出，網路逾時、瀏覽器重複送出或 proxy 重試都會造成重複照片、額外佔用每台車
// 60 張上限，且使用者無法確定哪些是第一次嘗試留下的紀錄，難以手動清理）。
//
// 上傳一次是「多檔案批次」，不是單一 row，因此沿用 vehicles/money_entries 既有的
// 「unique idempotency_key + payload 快照比對」模式，但用一張獨立的批次記錄表：
// 一次上傳對應一筆 batch row，記錄這次請求的檔案內容快照（payload）與目前已成功
// 建立的照片 id 清單（photo_ids），而不是把 idempotency_key 加到 vehicle_photos
// 本身（一次上傳會產生多張照片，無法讓多筆 row 共用同一個 unique key）。
//
// photo_ids 的語意在後續 2026_07_11_000000_add_processing_lease_to_vehicle_photo_upload_batches_table
// 微調過：改成每個檔案成功建立照片時就同步寫回，而不是等整批都做完才一次寫入，
// 詳見該檔案的說明。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_photo_upload_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->unique('idempotency_key', 'vehicle_photo_upload_batches_idempotency_key_unique');
            // 這次請求送出的檔案內容快照（依上傳順序排列的 sha256/size/原始檔名陣列），
            // 用來判斷重試請求是否「真的是同一次請求」，而不是剛好用了同一把 key 卻帶
            // 不同檔案的另一次請求（見 VehiclePhotoService::buildUploadPayload()）。
            $table->text('idempotency_payload');
            // 目前已成功建立的 vehicle_photos.id 清單（JSON array，依處理順序遞增）。
            // 建立時即初始化為 []，隨著每個檔案成功建立照片同步寫回；長度等於這次
            // 請求的檔案總數代表整批已完成，可直接回放（見
            // VehiclePhotoService::uploadPhotos() / beginUploadBatch()）。
            $table->text('photo_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_photo_upload_batches');
    }
};
