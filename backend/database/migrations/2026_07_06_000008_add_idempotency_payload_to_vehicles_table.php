<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // 儲存建車當下正規化後的欄位快照（JSON），讓「相同 idempotency_key 重試」
            // 一律比對「當初送出的內容」，而不是比對車輛目前（可能已被後續整備/上架/
            // 編輯流程合法修改過）的即時狀態，否則同一把 key 的合法重送會因為車輛後續
            // 被正常編輯過而被誤判成「不同建車內容」進而 422。
            $table->text('idempotency_payload')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('idempotency_payload');
        });
    }
};
