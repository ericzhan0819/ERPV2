<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 這支 migration 做兩件事：
     *
     * 1. 建立 money_entry_source_type_reviews：僅用於本次資料修復的安全記錄表，
     *    記錄後續 money-entries:source-type-review 指令每一次人工確認變更，
     *    不是產品審計模組，不得延伸成正式 audit log。
     *
     * 2. 保守 quarantine：既有資料在 source_type 欄位新增前，沒有任何 durable
     *    provenance marker，無法證明「source_type = manual 且綁定車輛」的既有
     *    收支到底是一般 CRUD 建立、還是曾經跑過舊版不安全 backfill heuristic
     *    誤判。因此把這批 rows 統一改成 legacy_unknown（保護狀態，禁止一般
     *    CRUD 修改/刪除），交由人工用 money-entries:source-type-review 逐筆
     *    確認後才能改回 manual / vehicle_shortcut / vehicle_workflow。
     *    vehicle_id IS NULL 的一般營運 manual 收支不受影響，仍維持 manual。
     *    既有 vehicle_shortcut / vehicle_workflow 不改。
     *
     * durable state（money_entry_source_type_quarantine_state）：cohort table
     * 是否存在不足以代表初始化已完成 —— MySQL DDL 會提交，若 process 在
     * cohort table 建好但 cohort 尚未 populate 完成、或 quarantine update
     * 尚未套用完成前 crash，下一次重跑不能只憑「cohort table 存在」就誤判成
     * 已完成而跳過剩餘工作。因此改用一張明確的 state 表記錄：
     *   - cutoff_id：第一次真正開始執行時固定的 money_entries max(id)，
     *     之後任何一次 resume/rerun 的 cohort 查詢都只能用這個 cutoff，不得
     *     用目前最新 max(id) 重新擴張快照邊界。
     *   - cohort_completed_at：cohort 是否已完整 populate。
     *   - quarantine_completed_at：quarantine update 是否已完整套用過一次。
     * 只有 quarantine_completed_at 已標記完成，才代表這是「真正的 rerun /
     * rollback 後重新 migrate」，走下方排除已人工 review 的邏輯；否則（state
     * 尚未存在、或存在但任一階段未完成）一律視為 resumable retry，補齊 cohort
     * 與 quarantine，不得因為 cohort 是空的或只有部分資料就直接跳過。
     *
     * down() 刻意 no-op（見下方說明），代表 Laravel migrations ledger 仍會被
     * 移除，下一次 migrate 會重新呼叫 up()。重跑（quarantine_completed_at 已
     * 完成過）時：
     *   - 只處理原本 cohort 內的 id，migration 後新建立、不屬於原 cohort 的
     *     合法 vehicle-bound manual 收支不會被誤傷。
     *   - 排除已經有 money_entry_source_type_reviews 紀錄（人工已明確
     *     review 過，不論最後改成什麼 source_type）的 id，避免把人工已經
     *     確認回 manual 的資料重新 quarantine。
     *   - 排除 candidate 已經 resolved 的 id（人工已經透過
     *     money-entries:source-type-review 確認過）。若 000003 建立的
     *     candidate 表尚不存在（例如 000002 先於 000003 crash），只排除
     *     reviews evidence，不查詢不存在的 candidate 表。
     *   - 全程只比對這兩張既有 evidence 表與 durable cohort 快照本身，不使用
     *     category / status / buyer_name / sold_price / idempotency_key 等
     *     heuristic。
     */
    public function up(): void
    {
        if (! Schema::hasTable('money_entry_source_type_reviews')) {
            Schema::create('money_entry_source_type_reviews', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('money_entry_id')->index();
                $table->string('previous_source_type', 30);
                $table->string('new_source_type', 30);
                $table->string('approver');
                $table->text('reason');
                $table->string('backup_path')->nullable();
                $table->json('money_entry_snapshot')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('money_entry_source_type_quarantine_cohort')) {
            Schema::create('money_entry_source_type_quarantine_cohort', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('money_entry_id')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('money_entry_source_type_quarantine_state')) {
            Schema::create('money_entry_source_type_quarantine_state', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cutoff_id');
                $table->timestamp('cohort_completed_at')->nullable();
                $table->timestamp('quarantine_completed_at')->nullable();
                $table->timestamps();
            });
        }

        $state = DB::table('money_entry_source_type_quarantine_state')->first();

        if (! $state) {
            $cutoffId = (int) (DB::table('money_entries')->max('id') ?? 0);

            $stateId = DB::table('money_entry_source_type_quarantine_state')->insertGetId([
                'cutoff_id' => $cutoffId,
                'cohort_completed_at' => null,
                'quarantine_completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $state = DB::table('money_entry_source_type_quarantine_state')->find($stateId);
        }

        if ($state->quarantine_completed_at !== null) {
            $this->requarantineExcludingReviewed();

            return;
        }

        if ($state->cohort_completed_at === null) {
            $cohortIds = DB::table('money_entries')
                ->where('source_type', 'manual')
                ->whereNotNull('vehicle_id')
                ->where('id', '<=', $state->cutoff_id)
                ->pluck('id');

            if ($cohortIds->isNotEmpty()) {
                $now = now();

                $cohortIds->chunk(500)->each(function ($chunk) use ($now) {
                    DB::table('money_entry_source_type_quarantine_cohort')->insertOrIgnore(
                        $chunk->map(fn ($id) => [
                            'money_entry_id' => $id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all()
                    );
                });
            }

            DB::table('money_entry_source_type_quarantine_state')
                ->where('id', $state->id)
                ->update(['cohort_completed_at' => now(), 'updated_at' => now()]);
        }

        $cohortIds = DB::table('money_entry_source_type_quarantine_cohort')->pluck('money_entry_id');

        if ($cohortIds->isNotEmpty()) {
            DB::table('money_entries')
                ->whereIn('id', $cohortIds->all())
                ->where('source_type', 'manual')
                ->update(['source_type' => 'legacy_unknown']);
        }

        DB::table('money_entry_source_type_quarantine_state')
            ->where('id', $state->id)
            ->update(['quarantine_completed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * 真正的 rerun / rollback 後重新 migrate：只處理原本 cohort，且排除已有
     * 人工 review 證據（reviews log 或已 resolved 的 candidate）的 id。
     */
    private function requarantineExcludingReviewed(): void
    {
        $reviewedIds = DB::table('money_entry_source_type_reviews')->pluck('money_entry_id');

        $resolvedCandidateIds = Schema::hasTable('money_entry_source_type_review_candidates')
            ? DB::table('money_entry_source_type_review_candidates')->whereNotNull('resolved_at')->pluck('money_entry_id')
            : collect();

        $excludedIds = $reviewedIds->merge($resolvedCandidateIds)->unique();

        $cohortIds = DB::table('money_entry_source_type_quarantine_cohort')->pluck('money_entry_id');
        $idsToRequarantine = $cohortIds->diff($excludedIds)->values();

        if ($idsToRequarantine->isEmpty()) {
            return;
        }

        DB::table('money_entries')
            ->whereIn('id', $idsToRequarantine->all())
            ->where('source_type', 'manual')
            ->update(['source_type' => 'legacy_unknown']);
    }

    /**
     * 有意保持保守：不批次把 legacy_unknown 改回 manual，避免又用另一種
     * heuristic 誤傷資料。若需要 rollback，資料復原請透過
     * money-entries:source-type-review 人工確認每一筆的正確來源。
     *
     * down() 刻意保持 no-op、不 drop money_entry_source_type_reviews、
     * money_entry_source_type_quarantine_cohort 或
     * money_entry_source_type_quarantine_state：這幾張表分別記錄人工
     * approver/reason/前後狀態/資料快照、本 migration 第一次執行當下的
     * cohort 快照，以及 durable cutoff/completion state，一旦 rollback 就會
     * 刪掉已完成的人工確認證據或重跑所需的邊界依據，之後重新 up() 也救不
     * 回來、也無法安全判斷重跑範圍。schema rollback 不應該連帶銷毀已經產生
     * 的人工審核紀錄或 cohort/state 快照。
     */
    public function down(): void
    {
        // 有意保持不刪除 money_entry_source_type_reviews、
        // money_entry_source_type_quarantine_cohort 或
        // money_entry_source_type_quarantine_state，避免刪掉已完成的人工
        // 審核證據，以及重跑時判斷 cohort 範圍與完成狀態所需的依據。
    }
};
