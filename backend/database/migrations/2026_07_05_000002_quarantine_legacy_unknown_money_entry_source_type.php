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
     * ledger-safe / 重跑安全：down() 刻意 no-op（見下方說明），代表 Laravel
     * migrations ledger 仍會被移除，下一次 migrate 會重新呼叫 up()。因此把
     * 「執行當下」符合條件的 money_entry id 快照進
     * money_entry_source_type_quarantine_cohort，只在第一次執行（判斷式：
     * 這張 cohort 表尚不存在）時建立快照並 quarantine。之後任何一次重跑：
     *   - 只處理原本 cohort 內的 id，migration 後新建立、不屬於原 cohort 的
     *     合法 vehicle-bound manual 收支不會被誤傷。
     *   - 排除已經有 money_entry_source_type_reviews 紀錄（人工已明確
     *     review 過，不論最後改成什麼 source_type）的 id，避免把人工已經
     *     確認回 manual 的資料重新 quarantine。
     *   - 排除 candidate 已經 resolved 的 id（人工已經透過
     *     money-entries:source-type-review 確認過）。
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

        $isFirstRun = ! Schema::hasTable('money_entry_source_type_quarantine_cohort');

        if ($isFirstRun) {
            Schema::create('money_entry_source_type_quarantine_cohort', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('money_entry_id')->unique();
                $table->timestamps();
            });

            $cohortIds = DB::table('money_entries')
                ->where('source_type', 'manual')
                ->whereNotNull('vehicle_id')
                ->pluck('id');

            if ($cohortIds->isEmpty()) {
                return;
            }

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

            DB::table('money_entries')
                ->whereIn('id', $cohortIds->all())
                ->where('source_type', 'manual')
                ->update(['source_type' => 'legacy_unknown']);

            return;
        }

        // 重跑（例如 rollback 後重新 migrate）：只處理當初的 cohort，且排除已
        // 有人工 review 證據（不論是 reviews log 或已 resolved 的 candidate）
        // 的 id，避免重新 quarantine 已人工確認回 manual 的資料。
        $reviewedIds = DB::table('money_entry_source_type_reviews')->pluck('money_entry_id');
        $resolvedCandidateIds = DB::table('money_entry_source_type_review_candidates')
            ->whereNotNull('resolved_at')
            ->pluck('money_entry_id');

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
     * down() 刻意保持 no-op、不 drop money_entry_source_type_reviews 或
     * money_entry_source_type_quarantine_cohort：這兩張表分別記錄人工
     * approver/reason/前後狀態/資料快照，以及本 migration 第一次執行當下的
     * cohort 快照，一旦 rollback 就會刪掉已完成的人工確認證據或 cohort
     * 邊界依據，之後重新 up() 也救不回來、也無法安全判斷重跑範圍。schema
     * rollback 不應該連帶銷毀已經產生的人工審核紀錄或 cohort 快照。
     */
    public function down(): void
    {
        // 有意保持不刪除 money_entry_source_type_reviews 或
        // money_entry_source_type_quarantine_cohort，避免刪掉已完成的人工
        // 審核證據，以及重跑時判斷 cohort 範圍所需的依據。
    }
};
