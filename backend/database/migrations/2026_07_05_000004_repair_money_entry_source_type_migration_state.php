<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 為什麼需要這支獨立的 forward-only repair migration：
     *
     * 000002 / 000003 的 durable state 邏輯（quarantine_state /
     * candidate_capture_state）是後來才補上的。任何已經在 000002 / 000003
     * 加入 durable state 之前就完整跑過這兩支 migration 的環境，migrations
     * ledger 裡已經記錄過同名 migration，Laravel 正常部署不會重新呼叫它們的
     * up()，所以把修補寫在 000002 / 000003 裡對這些已部署環境完全無效 ——
     * 這些環境永遠不會補建 durable state 表。因此升級路徑必須放在一支新的
     * migration，只依賴目前 schema 與既有 evidence 表，不依賴重跑
     * 000002 / 000003。
     *
     * 採用方案 A：保留 000002 / 000003 現有的 durable state 邏輯 ——
     * 那對「全新安裝」或「尚未套用過舊版 000002 / 000003」的環境仍然正確、
     * 仍然是第一線的 fresh-install 行為。這支 000004 只負責替「已經套用過
     * 舊版 000002 / 000003（不論有沒有 durable state 邏輯）」的環境補齊
     * durable state，讓 gate 與後續 rerun/rollback 判斷邏輯可以運作。
     *
     * 判斷方式：
     *   - 若 money_entry_source_type_quarantine_state /
     *     money_entry_source_type_review_candidate_capture_state 已經存在
     *     一筆 state row，代表 000002 / 000003 在這個環境上是「全新安裝」
     *     或已經自行完成過 durable state 初始化，本 migration 對該部分
     *     no-op。
     *   - 若 state 表不存在、或存在但沒有任何 row，代表這個環境套用的是
     *     舊版 000002 / 000003（沒有 durable state 邏輯），本 migration
     *     負責建立缺漏的表並用既有 evidence 保守回填 state。
     *
     * 對缺乏歷史 cutoff 的舊環境，刻意不使用 current max(id) 假裝歷史
     * cutoff（那樣會把 migration 之後才合法新增的資料誤判成落在歷史邊界
     * 內），只依既有 durable evidence（目前仍是 legacy_unknown 的 row、
     * 既有 candidate row）建立保守邊界，寧可邊界偏窄也不擴張快照。
     *
     * down() 刻意 no-op，理由與 000002 / 000003 相同：不得刪除人工 review
     * 證據、candidate 證據、cohort 或 durable state，否則會讓後續判斷邊界
     * 依據消失。
     */
    public function up(): void
    {
        $this->repairQuarantineState();
        $this->repairCandidateCaptureState();
    }

    private function repairQuarantineState(): void
    {
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

        if (DB::table('money_entry_source_type_quarantine_state')->exists()) {
            // 000002 已經在這個環境自行建立並完成 state 初始化（全新安裝，
            // 或已套用過含 durable state 邏輯的 000002），不需要 repair。
            return;
        }

        // 走到這裡代表：這個環境套用的是「沒有 durable state 邏輯」的舊版
        // 000002。舊版沒有留下任何「migration 第一次執行當下」的 cutoff
        // 記錄，無法可靠回推歷史邊界。改用保守 evidence-based 修復：
        //   - cutoff_id 使用 0 作為明確 sentinel，代表「舊環境 repair 無法
        //     重建歷史 cutoff」，不具備日後擴張快照的意義。
        //   - cohort 只補進「目前仍是 legacy_unknown」的既有列 —— 這些資料
        //     的 source_type 不會被一般 CRUD 產生，明確是舊版 quarantine
        //     遺留，無論 cohort table 先前是否已存在、是否已有部分資料，
        //     都可以安全 insertOrIgnore 補齊，不會誤納入 migration 後才
        //     合法新增的 manual 資料。
        //   - 不重新掃描 source_type=manual AND vehicle_id IS NOT NULL 的
        //     資料：那批可能包含本次修復之前已經人工 review 確認回 manual
        //     的資料，也可能包含本次修復之後才合法新增的資料，兩者都不應
        //     該被納入 cohort。
        $legacyUnknownIds = DB::table('money_entries')
            ->where('source_type', 'legacy_unknown')
            ->pluck('id');

        if ($legacyUnknownIds->isNotEmpty()) {
            $now = now();

            $legacyUnknownIds->chunk(500)->each(function ($chunk) use ($now) {
                DB::table('money_entry_source_type_quarantine_cohort')->insertOrIgnore(
                    $chunk->map(fn ($id) => [
                        'money_entry_id' => $id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
        }

        // 這些 row 目前就是 legacy_unknown，quarantine 這個動作本身已經
        // 完成，只是缺 durable state 紀錄；因此 cohort/quarantine 都直接
        // 標記為已完成，讓日後任何一次重跑只走
        // requarantineExcludingReviewed()（只處理這裡建立的 cohort，並排除
        // 已有人工 review/resolved candidate 證據的 id），不會再重新掃描
        // 全表。
        DB::table('money_entry_source_type_quarantine_state')->insert([
            'cutoff_id' => 0,
            'cohort_completed_at' => now(),
            'quarantine_completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function repairCandidateCaptureState(): void
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

        if (DB::table('money_entry_source_type_review_candidate_capture_state')->exists()) {
            // 000003 已經在這個環境自行建立並完成 state 初始化，不需要
            // repair。
            return;
        }

        // 舊版 000003 沒有 durable cutoff/state。若這個環境已經跑過舊版
        // 000003，money_entry_source_type_review_candidates 可能已經有既有
        // rows —— 這些 rows 本身就是舊版當時捕捉下來的 snapshot 證據，用
        // 既有 candidate rows 的 max(money_entry_id) 當作 conservative
        // captured boundary，不使用 current money_entries max(id)（那會把
        // migration 之後才合法新增的 vehicle_shortcut/vehicle_workflow 收支
        // 誤判成落在「看似已捕捉」的邊界內，但實際上從未真正被捕捉，日後
        // 排除邏輯可能誤判為需要 review）。
        //
        // 若 candidate table 完全沒有任何 row（例如這個環境曾經套用
        // 000002，但部署中斷在 000003 之前，或這個環境本來就沒有被舊版
        // 不安全 heuristic 誤標過任何收支），代表沒有任何可靠證據可以重建
        // 「migration 第一次執行當下」的 protected entries 快照。這種情況
        // 下刻意不補查詢 current vehicle_shortcut/vehicle_workflow 資料塞入
        // candidate —— 那樣等於用 current max(id) 冒充歷史 cutoff，一樣會
        // 誤捕 migration 之後才合法新增的資料。因此只建立空的 completed
        // state（cutoff=0 sentinel），承認此邊界情境無法完整還原，優先避免
        // 擴張快照，而不是假裝完整。
        $cutoffId = (int) (DB::table('money_entry_source_type_review_candidates')->max('money_entry_id') ?? 0);

        DB::table('money_entry_source_type_review_candidate_capture_state')->insert([
            'cutoff_id' => $cutoffId,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * forward-only、保守：不刪除 quarantine state/cohort、candidate capture
     * state，或既有的 review/candidate 證據表。rollback 不應該銷毀這支
     * migration 建立的 durable state，否則會讓下一次重新 migrate 又找不到
     * state 而重新走一次 repair 邏輯，且無法保證期間資料沒有變動。
     */
    public function down(): void
    {
        // 有意保持不刪除任何 durable state / cohort / candidate / review
        // 表，避免破壞已完成的 repair 結果與其判斷依據。
    }
};
