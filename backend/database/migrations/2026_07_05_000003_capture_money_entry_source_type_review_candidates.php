<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * forward-only：建立 money_entry_source_type_review_candidates，並把「本
     * migration 第一次真正開始執行當下」需要人工 review 的既有收支拍照下來。
     *
     * 背景：000002 只把 source_type=manual 且綁定車輛的既有收支 quarantine 成
     * legacy_unknown。但如果某個環境曾經跑過舊版不安全 backfill（用
     * category / 車輛狀態 / buyer_name / sold_price 等 heuristic），合法
     * manual 收支可能已經被誤標成 vehicle_shortcut / vehicle_workflow —— 這批
     * 資料不是 manual，不會被 000002 quarantine，若 gate 只看 legacy_unknown
     * 會誤判通過。因此本 migration 把「第一次真正開始執行」那一刻所有
     * legacy_unknown，以及當下所有 vehicle_shortcut / vehicle_workflow，一併
     * 拍照存進 candidate 表，交由 money-entries:source-type-gate 卡住部署，
     * 直到全部人工 review。
     *
     * candidate 只代表「第一次真正開始執行那一刻」的快照，之後由
     * VehicleService::reserveVehicle() / recordFinalPayment() /
     * MoneyEntryService::recordVehicleShortcut() 正常建立的新
     * vehicle_shortcut / vehicle_workflow 收支不會被補進這張表，也就不會被
     * gate 檔住。
     *
     * candidate 不加 FK，避免日後合法刪除 money_entry 卡住 migration／刪除
     * 動作；用 unsignedBigInteger + unique 索引避免重複捕捉同一筆。
     *
     * durable cutoff / 完成 state
     * （money_entry_source_type_review_candidate_capture_state）：down() 刻意
     * no-op，代表 Laravel migrations ledger 仍會被移除，下一次 migrate 會重新
     * 呼叫 up()。若每次 up() 都重新用「目前」money_entries 最大 id 當
     * cutoffId，只能保護單次 invocation：rollback 後重新 migrate 時，migration
     * 之後才正常新增的合法 vehicle_shortcut / vehicle_workflow 收支，id 會落在
     * 新算出的 cutoff 之內而被誤補進 candidate、誤擋 gate。因此改成：
     *   - 第一次執行時固定 cutoffId = 當下 money_entries max(id)，寫進 state
     *     表並先標記未完成。
     *   - 之後任何一次 resume/rerun，一律重用 state 表裡的原始 cutoffId，不
     *     得重新用目前最新 max(id) 擴張快照邊界。
     *   - state 標記完成後，代表 candidate 快照已經 populate 完整，之後任何
     *     一次 up() 直接 no-op，不再重新查詢、不再擴張快照。
     *   - state 存在但未標記完成（例如 partial failure），視為可安全 retry：
     *     重用同一 cutoffId 重新呼叫 captureCandidates()（insertOrIgnore 天生
     *     幂等），完成後才標記 completed。
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

        if (! Schema::hasTable('money_entry_source_type_review_candidate_capture_state')) {
            Schema::create('money_entry_source_type_review_candidate_capture_state', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cutoff_id');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        $state = DB::table('money_entry_source_type_review_candidate_capture_state')->first();

        if (! $state) {
            $cutoffId = (int) (DB::table('money_entries')->max('id') ?? 0);

            $stateId = DB::table('money_entry_source_type_review_candidate_capture_state')->insertGetId([
                'cutoff_id' => $cutoffId,
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $state = DB::table('money_entry_source_type_review_candidate_capture_state')->find($stateId);
        }

        if ($state->completed_at !== null) {
            return;
        }

        $cutoffId = (int) $state->cutoff_id;

        if ($cutoffId <= 0) {
            DB::table('money_entry_source_type_review_candidate_capture_state')
                ->where('id', $state->id)
                ->update(['completed_at' => now(), 'updated_at' => now()]);

            return;
        }

        $this->captureCandidates('legacy_unknown', 'legacy_vehicle_bound_manual_quarantine', $cutoffId);
        $this->captureCandidates(['vehicle_shortcut', 'vehicle_workflow'], 'preexisting_protected_source_type_needs_review', $cutoffId);

        DB::table('money_entry_source_type_review_candidate_capture_state')
            ->where('id', $state->id)
            ->update(['completed_at' => now(), 'updated_at' => now()]);
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
     * forward-only、保守：不刪除 candidate 快照、resolved_at/resolved_by
     * 解決紀錄，或 durable cutoff/完成 state。rollback 不應該銷毀尚未確認
     * 完成的人工 review 判斷依據，也不應該讓下一次重新 migrate 因為 state
     * 消失而重新用最新 max(id) 擴張快照邊界。
     */
    public function down(): void
    {
        // 有意保持不刪除 money_entry_source_type_review_candidates 或
        // money_entry_source_type_review_candidate_capture_state，避免刪掉
        // candidate 快照、已完成的解決紀錄，以及 durable cutoff/完成狀態。
    }
};
