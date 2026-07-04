<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * forward-only：建立 money_entry_source_type_review_candidates，並把「本
     * migration 執行當下」需要人工 review 的既有收支拍照下來。
     *
     * 背景：000002 只把 source_type=manual 且綁定車輛的既有收支 quarantine 成
     * legacy_unknown。但如果某個環境曾經跑過舊版不安全 backfill（用
     * category / 車輛狀態 / buyer_name / sold_price 等 heuristic），合法
     * manual 收支可能已經被誤標成 vehicle_shortcut / vehicle_workflow —— 這批
     * 資料不是 manual，不會被 000002 quarantine，若 gate 只看 legacy_unknown
     * 會誤判通過。因此本 migration 把執行當下所有 legacy_unknown，以及執行
     * 當下所有 vehicle_shortcut / vehicle_workflow，一併拍照存進 candidate
     * 表，交由 money-entries:source-type-gate 卡住部署，直到全部人工 review。
     *
     * candidate 只代表「本 migration 執行那一刻」的快照，之後由
     * VehicleService::reserveVehicle() / recordFinalPayment() /
     * MoneyEntryService::recordVehicleShortcut() 正常建立的新
     * vehicle_shortcut / vehicle_workflow 收支不會被補進這張表，也就不會被
     * gate 檔住。
     *
     * candidate 不加 FK，避免日後合法刪除 money_entry 卡住 migration／刪除
     * 動作；用 unsignedBigInteger + unique 索引避免重複捕捉同一筆。
     *
     * 非原子快照防護：capture 開始前先固定 cutoffId = money_entries 目前最大
     * id，所有 candidate capture query 都加上 id <= cutoffId。migration
     * 執行期間（或之後）新增的合法 vehicle_shortcut / vehicle_workflow
     * 收支，id 必然大於 cutoffId，不會被補進 candidate，也就不會被 gate
     * 誤擋。candidate 表語意維持「000003 migration 執行那一刻」的快照。
     */
    public function up(): void
    {
        if (! Schema::hasTable('money_entry_source_type_review_candidates')) {
            Schema::create('money_entry_source_type_review_candidates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('money_entry_id')->unique();
                $table->string('captured_source_type', 30);
                $table->string('candidate_reason', 60);
                $table->json('money_entry_snapshot')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->string('resolved_by')->nullable();
                $table->unsignedBigInteger('resolution_review_id')->nullable();
                $table->timestamps();
            });
        }

        $cutoffId = (int) (DB::table('money_entries')->max('id') ?? 0);

        if ($cutoffId <= 0) {
            return;
        }

        $this->captureCandidates('legacy_unknown', 'legacy_vehicle_bound_manual_quarantine', $cutoffId);
        $this->captureCandidates(['vehicle_shortcut', 'vehicle_workflow'], 'preexisting_protected_source_type_needs_review', $cutoffId);
    }

    /**
     * @param  string|array<int, string>  $sourceTypes
     */
    private function captureCandidates(string|array $sourceTypes, string $reason, int $cutoffId): void
    {
        $now = now();

        DB::table('money_entries')
            ->whereIn('source_type', (array) $sourceTypes)
            ->where('id', '<=', $cutoffId)
            ->orderBy('id')
            ->chunkById(200, function ($entries) use ($reason, $now) {
                $rows = $entries->map(fn ($entry) => [
                    'money_entry_id' => $entry->id,
                    'captured_source_type' => $entry->source_type,
                    'candidate_reason' => $reason,
                    'money_entry_snapshot' => json_encode((array) $entry, JSON_THROW_ON_ERROR),
                    'resolved_at' => null,
                    'resolved_by' => null,
                    'resolution_review_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if (! empty($rows)) {
                    DB::table('money_entry_source_type_review_candidates')->insertOrIgnore($rows);
                }
            });
    }

    /**
     * forward-only、保守：不刪除 candidate 快照與 resolved_at/resolved_by
     * 解決紀錄。rollback 不應該銷毀尚未確認完成的人工 review 判斷依據。
     */
    public function down(): void
    {
        // 有意保持不刪除 money_entry_source_type_review_candidates，避免刪掉
        // candidate 快照與已完成的解決紀錄。
    }
};
