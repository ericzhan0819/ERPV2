<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Codex adversarial review（第四輪）指出：前一版的租約回收機制只在「認領」那一刻
// 核發新的 processing_lease_expires_at，卻沒有在認領之後的每一次寫入都驗證「這次
// 寫入的人是不是目前真正的擁有者」。租約過期後被另一個請求續傳認領時，原本那個
// 請求即使還在跑（只是比租約久，不是真的已經死掉）、還握著舊的 $batch 物件，後續
// 對 photo_ids／processing_lease_expires_at 的寫入完全不會被擋下，可能覆蓋掉新
// 擁有者已經寫入的進度，甚至在自己之後失敗時，把新擁有者已經回傳給使用者的照片
// 從 photo_ids 清單裡復原掉（雖然不會刪除照片本體，但會讓之後的回放查不到）。
//
// 加上 claim_token：每次「認領」這筆 batch（不管是第一次建立，還是租約過期後被
// 續傳認領）都會核發一把新的 token。之後任何一次寫入 photo_ids 或
// processing_lease_expires_at，都必須用 WHERE id = ? AND claim_token = ? 當作
// fencing 條件——只有 token 相符（代表呼叫端此刻仍然是真正的擁有者）才會真的寫入，
// 一旦被取代（token 已經換過），寫入會影響 0 筆，呼叫端必須偵測到這件事並停止繼續
// 動這個 batch（見 VehiclePhotoService::applyBatchUpdateIfOwned() /
// 此段說明相鄰程式碼的用途與預期行為。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_photo_upload_batches', function (Blueprint $table) {
            $table->string('claim_token', 36)->nullable()->after('processing_lease_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_photo_upload_batches', function (Blueprint $table) {
            $table->dropColumn('claim_token');
        });
    }
};
