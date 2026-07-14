<?php

namespace App\Services;

use App\Exceptions\VehiclePhotoUploadBatchSupersededException;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Models\VehiclePhotoUploadBatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VehiclePhotoService
{
    public function __construct(
        private readonly VehiclePhotoImageProcessor $processor,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function listPhotos(Vehicle $vehicle): Collection
    {
        return $vehicle->photos()->get();
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, VehiclePhoto>
     */
    public function uploadPhotos(Vehicle $vehicle, User $user, array $files, string $idempotencyKey): Collection
    {
        $config = config('vehicle_photos');

        if (count($files) > $config['max_files_per_upload']) {
            throw ValidationException::withMessages([
                'photos' => "單次上傳最多 {$config['max_files_per_upload']} 張照片。",
            ]);
        }

        // 一次上傳會建立多張照片，無法讓多筆 vehicle_photos row 共用同一個 unique
        // idempotency_key（沿用 vehicles/money_entries 既有的「unique key + payload
        // 快照比對」模式，但用獨立的 vehicle_photo_upload_batches 記錄表記這次請求的
        // 檔案內容快照與目前已完成的 photo_ids）。網路逾時、瀏覽器重複送出或 proxy
        // 重試帶著同一把 key 重打這個端點時，內容相同就直接回傳當初建立的照片、不
        // 重複建立；內容不同則拒絕（Codex adversarial review 指出先前完全沒有這層
        // 保護，重試會不斷疊加重複照片並吃掉每台車 60 張上限）。
        $payload = $this->buildUploadPayload($vehicle, $files);
        $begin = $this->beginUploadBatch($vehicle, $idempotencyKey, $payload);
        $batch = $begin['batch'];

        if ($begin['mode'] === 'replay') {
            // 正常情況下這批照片在上一次真正完成處理時，最後一個檔案的
            // transaction 就已經連同「達到完成」跟「曝光」一起原子性 commit 過了
            // （見下面 finalizeBatchVisibility() 呼叫點）。這裡仍然在每一次回放時
            // 都重新做一次同樣冪等的曝光/補封面動作，是為了涵蓋這個原子化修正
            // 上線前就已經卡住的既有 row（Codex stop-time review 指出的中斷視窗：
            // 舊版把「達到完成」與「曝光」分成兩個獨立寫入步驟，程序在兩者之間
            // 中斷會讓照片永遠卡在已完成但不可見的狀態）：多數情況下這些照片早已
            // 是可見狀態、也已有封面，這裡的 UPDATE 不會匹配到任何 row，是沒有
            // 副作用的健康檢查；只有真的卡在舊有中斷情境時，才會在這裡自動修復，
            // 不需要使用者知道也不需要人工介入。鎖住車輛 row 後才執行，確保跟
            // 另一個並發請求的封面判斷序列化、不會互相踩踏。
            // 稽核回填（見下方 auditLogMissingUploadEvents() 的說明）必須跟
            // finalizeBatchVisibility() 包在同一個、由 lockForUpdate() 鎖住這台車
            // row 的 transaction 內完成，不能等 transaction commit 之後才另外做
            // 「查詢是否已記錄 → 沒有就補插入」。若查詢與插入這兩步之間沒有鎖保護，
            // 兩個幾乎同時抵達的並發 replay 請求（例如同一把 idempotency_key 被
            // 瀏覽器或 proxy 重試）可能都在對方插入之前完成查詢、都判斷「還沒有
            // 紀錄」，最終各自补插入一筆，讓同一張照片產生兩筆重複的 created 稽核
            // 紀錄（Codex stop-time review 指出）。用同一個車輛 row lock 把「回放
            // 曝光/補封面」與「稽核回填」序列化，確保後到的並發請求一定會在自己的
            // 查詢時就看到前一個請求已經 commit 的稽核紀錄。
            $replayedPhotos = DB::transaction(function () use ($vehicle, $batch) {
                Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
                $this->finalizeBatchVisibility($vehicle, $batch->photo_ids ?? []);

                $photos = $this->photosInOrder($batch->photo_ids ?? []);

                // 這批照片有可能是「上一次真正建立它們的那次呼叫」在跑到函式最後面
                // 的稽核記錄之前就被中止（worker 被強制中止、fatal error、伺服器
                // 重啟）：照片本身透過逐檔 transaction 已經真的 commit 到 DB，但
                // 那次呼叫從未執行到底部的 recordModelEvent()，導致這些照片永久
                // 沒有 'created' 稽核紀錄，即使之後每一次 replay 都只會回放既有
                // row、不會再有機會補記（Codex stop-time review 指出：先前版本
                // replay 分支完全沒有呼叫任何稽核邏輯）。用「這張照片是否已經有
                // 一筆 created 稽核紀錄」查 DB 判斷，而不是任何記憶體旗標，才能
                // 涵蓋「建立它的那次呼叫」與「這次 replay」是兩個完全不同的 PHP
                // process 這種情況。
                $this->auditLogMissingUploadEvents($photos);

                return $photos;
            });

            return $replayedPhotos;
        }

        // claim_token 是這次呼叫「認領」這筆 batch 時核發的 fencing token（見
        // beginUploadBatch()）。租約過期後被另一個請求續傳認領時，會核發一把新的
        // token；原本那個呼叫端即使還在跑、還握著舊的 $batch 物件，後續任何寫入都
        // 必須帶著這裡拿到的 token 才會生效，token 一旦被換過就一律失敗，不會覆蓋
        // 新擁有者的進度（Codex adversarial review 第四輪指出：先前版本只在認領當下
        // 核發新租約，卻沒有在認領之後的每一次寫入都驗證擁有權，被取代的舊請求仍可能
        // 繼續寫壞新請求的進度，甚至在自己失敗時把新擁有者已經回傳給使用者的照片
        // 從 photo_ids 清單裡復原掉）。
        $claimToken = $begin['claimToken'];

        // 'new' 模式從頭開始（$alreadyDoneIds 為空）；'resume' 模式是續傳一批租約過期、
        // 前一次處理程序已放棄的批次，$alreadyDoneIds 是它上次成功處理到的檔案清單
        // （依 payload 的檔案順序），只需要接著處理剩下的檔案，不重做已經真的建立過
        // 照片的部分（Codex adversarial review 第三輪指出：先前版本回收逾時批次時會
        // 整批重新處理，對已經部分完成的批次而言等於系統性重複建立照片）。
        $alreadyDoneIds = $batch->photo_ids ?? [];
        $alreadyDonePhotos = $this->photosInOrder($alreadyDoneIds)->all();
        $filesToProcess = array_slice($files, count($alreadyDoneIds));
        $totalFilesCount = count($files);

        $created = $alreadyDonePhotos;
        $newlyCreatedThisCall = [];

        // 圖片解碼/編碼（process()）刻意放在 DB transaction 之外執行：
        // VehiclePhotoImageProcessor 內部已用全域 lock 保護並發記憶體風險，若整段
        // 包進單一 DB transaction，會讓資料庫連線在漫長的圖片處理期間被佔用。每個
        // 檔案各自 process → create，任一步驟失敗都會清理「這個檔案自己」寫入的
        // storage 檔案（PLAN v1.2 第 2.5 節：檔案寫入失敗時不可留下 DB row，DB 寫入
        // 失敗時要有檔案清理策略）。
        //
        // 每張照片的「每台車照片上限 / 封面 / sort_order / 寫回 batch.photo_ids」都
        // 包在同一個 transaction 內，並先用 lockForUpdate() 鎖住該車輛的 row：若不鎖，
        // 兩個使用者同時對同一台車上傳照片時，各自讀到的既有照片數 / 封面狀態 /
        // 最大 sort_order 都可能是同一份舊快照，導致兩者都以為自己是第一張封面、
        // sort_order 互撞，或兩者各自檢查上限都通過但疊加起來超過
        // max_photos_per_vehicle（Codex adversarial review 指出）。用同一台車的
        // row lock 把「檢查上限 + 決定封面 + 決定 sort_order + 寫入 + 更新
        // batch.photo_ids」序列化，確保並發請求看到的一定是彼此的最新結果，也確保
        // 每張照片一旦 commit，這次上傳的進度就同時、原子性地反映在 batch 上——不會
        // 出現「照片已建立、但 batch.photo_ids 還沒更新」的中間狀態，之後不管是續傳
        // 還是回放都一定看得到跟實際 DB 一致的進度。寫回 batch.photo_ids 時用
        // applyBatchUpdateIfOwned() 帶上 claim_token fencing：若這次寫入失敗（代表
        // 這個請求已經被取代），整個 transaction（含剛建立的照片）一併 rollback，
        // 不會留下沒被記錄進 batch 的孤兒照片。
        try {
            // 在真正呼叫 processor->process()（解碼/縮圖，會吃 CPU、記憶體與磁碟
            // I/O）之前，先用目前已知的照片數量做一次便宜的容量預檢：如果這台車
            // 現有照片數加上這批還沒處理的檔案數已經確定會超過
            // max_photos_per_vehicle，代表這通呼叫最終一定會在下面的 per-file
            // transaction 檢查失敗、並整批 rollback，與其花資源處理完前面幾個
            // 檔案才在最後一個檔案失敗，不如提早失敗（Codex adversarial review
            // 指出：先前版本會先把每個檔案都跑完整個圖片處理流程，才在 DB
            // transaction 內判斷是否超過上限，等於讓一台已滿的車輛可以被重複打去
            // 消耗圖片處理資源，每次都保證失敗）。這裡刻意放在 try 區塊內、且在
            // foreach 之前就丟出例外：必須讓下面同一個 catch 區塊接手，用
            // claim_token fencing 把這次呼叫開始前的 batch.photo_ids／租約復原、
            // 並清空 processing lease（Codex 第二輪 review 指出：先前把這段預檢放
            // 在 try 區塊外面，丟出的例外完全繞過復原/清租約邏輯，會讓這個
            // idempotency_key 的 batch 一路帶著未過期的 lease 卡住，直到 TTL 自然
            // 到期前，同一把 key 的合法重試都會被誤判成「仍在處理中」而被拒絕）。
            // 這裡不需要 lockForUpdate：正確性仍然由下面 per-file transaction 內的
            // row lock + 上限檢查保證，這裡只是儘早擋掉明顯會失敗的請求，不是
            // 唯一的把關點。
            //
            // 這裡只能算「穩定容量」：對外可見的照片（upload_batch_id 為 NULL）
            // 加上自己這個 batch 已經寫入的照片（upload_batch_id = 這個 batch）。
            // 不可以把「其他 batch 尚未提交、還在處理中」的 hidden row 也算進去
            // ——那些 row 屬於別的並發請求，有可能在它失敗時被復原/soft-delete，
            // 屆時容量會立刻空出來。若這裡把它們算進上限，會讓一個原本可以在
            // 那個並發請求結算後成功的合法請求，被這個便宜的預檢提早、錯誤地
            // 打回 422（Codex adversarial review 指出）。真正權威的判斷仍然是
            // 下面 per-file transaction 內、鎖住車輛 row 之後的即時計數；這裡
            // 刻意保守，只用來擋掉「就算等其他並發請求結算完，容量還是不夠」
            // 這種確定會失敗的情況。
            $currentPhotoCount = VehiclePhoto::where('vehicle_id', $vehicle->id)
                ->where(function ($query) use ($batch) {
                    $query->whereNull('upload_batch_id')->orWhere('upload_batch_id', $batch->id);
                })
                ->count();
            if ($currentPhotoCount + count($filesToProcess) > $config['max_photos_per_vehicle']) {
                throw ValidationException::withMessages([
                    'photos' => "每台車最多只能有 {$config['max_photos_per_vehicle']} 張照片。",
                ]);
            }

            foreach ($filesToProcess as $file) {
                $data = $this->processor->process($file, $vehicle->id);

                try {
                    $photo = DB::transaction(function () use ($vehicle, $user, $data, $config, $batch, $claimToken, $totalFilesCount) {
                        Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

                        $currentCount = VehiclePhoto::where('vehicle_id', $vehicle->id)->count();
                        if ($currentCount + 1 > $config['max_photos_per_vehicle']) {
                            throw ValidationException::withMessages([
                                'photos' => "每台車最多只能有 {$config['max_photos_per_vehicle']} 張照片。",
                            ]);
                        }

                        $nextSortOrder = (int) (VehiclePhoto::where('vehicle_id', $vehicle->id)->max('sort_order') ?? -1) + 1;

                        $photo = VehiclePhoto::create([
                            ...$data,
                            'vehicle_id' => $vehicle->id,
                            // 逐檔建立當下先記錄它屬於哪個 batch，視為「尚未提交、
                            // 暫不可見」（Vehicle::photos() 的 visible() scope 會濾掉
                            // upload_batch_id 不為 NULL 的照片）。整批上傳全部成功後，
                            // 這個 transaction 才會（在 batch 剛好完成的那一次呼叫）
                            // 一次性把這批照片的 upload_batch_id 清成 NULL，才視為
                            // 正式提交完成、對外可見（Codex adversarial review 指出：
                            // 先前版本每個檔案一建立就立刻透過一般列表/封面/排序操作
                            // 對外可見，中途永久放棄時被排程 sweep 掉等於憑空拿走
                            // 使用者已經看過的資料）。
                            'upload_batch_id' => $batch->id,
                            'sort_order' => $nextSortOrder,
                            // 建立當下一律不設封面，即使這是這台車第一張照片也一樣。
                            // 舊版在這裡直接查「這台車是否已有 is_cover=true 的
                            // row」，但那個查詢沒有排除還在其他批次中途、尚未提交的
                            // hidden 照片：如果另一個批次先佔走了封面，之後卻失敗
                            // rollback 或被判定放棄而 sweep 掉，這裡本來會誤以為
                            // 「已經有封面」而不幫這批照片指定封面，導致整台車最終
                            // 沒有任何封面（Codex adversarial review 指出）。封面
                            // 一律留到整批確定成功、正式曝光的那一刻才在
                            // finalizeBatchVisibility() 內判斷。
                            'is_cover' => false,
                            'uploaded_by' => $user->id,
                        ]);

                        $newPhotoIds = [...($batch->photo_ids ?? []), $photo->id];

                        $owned = $this->applyBatchUpdateIfOwned($batch, $claimToken, [
                            'photo_ids' => $newPhotoIds,
                        ]);

                        if (! $owned) {
                            throw new VehiclePhotoUploadBatchSupersededException(
                                '此照片上傳的 idempotency_key 已被另一個請求續傳認領。'
                            );
                        }

                        // 這是這批上傳最後一個檔案：在「寫入 photo_ids 達到完成」的
                        // 同一個 transaction 內立刻曝光，不能分成兩個獨立步驟。先前
                        // 版本把曝光（markBatchPhotosVisible）放在整個 foreach 迴圈
                        // 結束之後才單獨呼叫，如果程序在「最後一個檔案的 photo_ids
                        // 已經 commit」與「曝光的 UPDATE 執行或 commit」之間中斷
                        // （worker 被強制中止、程序崩潰），這批照片就會卡在「已被
                        // beginUploadBatch() 視為完成、之後永遠直接回放」但
                        // upload_batch_id 從未清空、永遠對外不可見的狀態，且沒有任何
                        // 排程會再重新嘗試曝光它們（Codex adversarial review 指出）。
                        // 曝光跟「達成完成」寫在同一個 transaction、同一次 commit，
                        // 就不會再有這個中斷視窗。
                        if (count($newPhotoIds) >= $totalFilesCount) {
                            $this->finalizeBatchVisibility($vehicle, $newPhotoIds);
                        }

                        return $photo;
                    });
                } catch (\Throwable $e) {
                    $this->processor->delete($data['disk'], $data['path'], $data['thumbnail_path']);

                    throw $e;
                }

                $created[] = $photo;
                $newlyCreatedThisCall[] = $photo;
            }
        } catch (\Throwable $e) {
            // 不論這次失敗的原始原因是什麼（單一檔案處理失敗、超過每台車照片上限，
            // 或是在上面的 transaction 內就已經偵測到 fencing 失敗），都必須在
            // 「同一個 DB transaction」內同時完成兩件事：(1) 用 claim_token fencing
            // 把 batch.photo_ids／租約復原回這次呼叫開始前的進度、(2) 把這次呼叫
            // 自己建立的照片 soft-delete（對外隱藏）。這兩件事的結果本身，就是
            // 「此刻我是否仍然擁有這個 batch」最新、最準確的答案——比起單純檢查
            // 「是不是 VehiclePhotoUploadBatchSupersededException」更可靠：後者只
            // 能偵測到「在處理某個檔案的當下就已經被取代」，偵測不到「檔案處理本身
            // 因為其他原因失敗（例如圖片損毀），但在準備清理善後的這一刻，才發現
            // 自己其實已經被取代」這種情況。
            //
            // 這兩件事必須同一個 transaction 原子性完成，不能像先前版本一樣分成
            // 「先復原 batch → 再刪除照片」兩個獨立步驟：一旦 batch 復原先 commit，
            // 另一個帶著同一把 idempotency_key 的請求就可能立刻認領、重新處理這幾個
            // 檔案，而此時舊照片可能都還「可見」（尚未被刪除），造成短暫但真實的
            // 重複可見視窗；若接下來 finalizePhysicalDeletion() 的 storage 清理失敗
            // 又沒有先 soft-delete，這些孤兒照片甚至會永久留著、繼續佔用每台車 60
            // 張上限、繼續被其他查詢看到（Codex adversarial review 第六輪指出）。
            // 把兩者包進同一個 transaction 後，任何後續請求要嘛在這個 transaction
            // commit 之前完全看不到 batch 復原（fencing 仍會保守拒絕），要嘛在
            // commit 之後同時看到「batch 已復原」與「這些照片已經對外隱藏」，不會
            // 有中間的不一致狀態。
            try {
                $stillOwned = $this->restoreBatchAndSoftDeleteRolledBackPhotos(
                    $vehicle,
                    $batch,
                    $claimToken,
                    $alreadyDoneIds,
                    $newlyCreatedThisCall,
                );
            } catch (\Throwable $cleanupException) {
                // 這個復原/soft-delete transaction 本身可能因為暫時性的 DB 問題
                // （deadlock、lock wait timeout 等）失敗，即使已經在
                // restoreBatchAndSoftDeleteRolledBackPhotos() 內用
                // DB::transaction($callback, 3) 讓 Laravel 自動重試 deadlock 三次
                // 仍不保證一定成功（Codex adversarial review 第七輪指出：先前版本
                // 完全沒有處理這個 transaction 自己失敗的情況，會讓底層原始 SQL
                // 例外直接外洩，蓋掉真正的失敗原因）。
                //
                // 因為整個復原/soft-delete 是包在單一 DB transaction 內，一旦這個
                // transaction 沒有 commit，就完全沒有任何東西被改動：這次呼叫自己
                // 建立的照片（$newlyCreatedThisCall）與 batch.photo_ids／租約，都還
                // 停留在「失敗前最後一次成功寫入」的狀態，不是不上不下的半殘狀態。
                // 這代表系統不會因此永久卡死——只是沒能立即復原成可重試，會退回
                // 沿用既有的租約 TTL 機制（見 beginUploadBatch()）：租約到期後，
                // 之後帶著同一把 idempotency_key 的請求一樣能安全續傳，不需要人工
                // 介入資料庫。這裡只需要老實記錄兩個例外（原始失敗原因 + 復原失敗
                // 原因），並回傳一個誠實、可重試的錯誤，不能讓底層原始的 SQL 例外
                // 直接外洩，也不能假裝復原成功繼續往下刪除照片。
                Log::warning('車輛照片上傳失敗後的批次復原/清理本身也失敗，將退回租約 TTL 機制自然復原', [
                    'vehicle_id' => $vehicle->id,
                    'idempotency_key' => $batch->idempotency_key,
                    'original_exception' => $e->getMessage(),
                    'cleanup_exception' => $cleanupException->getMessage(),
                ]);

                throw ValidationException::withMessages([
                    'photos' => '照片上傳失敗，且系統清理暫時發生問題，請稍後重新查詢確認結果或重試。',
                ]);
            }

            if (! $stillOwned) {
                Log::warning('車輛照片上傳批次已被其他請求續傳認領，放棄後續處理並保留既有照片', [
                    'vehicle_id' => $vehicle->id,
                    'idempotency_key' => $batch->idempotency_key,
                    'original_exception' => $e->getMessage(),
                ]);

                throw ValidationException::withMessages([
                    'idempotency_key' => '此照片上傳已被其他請求接手處理，請重新查詢確認結果。',
                ]);
            }

            foreach ($newlyCreatedThisCall as $photo) {
                // 這些照片在上面的 transaction 內已經 soft-delete、對外完全隱藏
                // （跟 deletePhoto() 相同的「邏輯刪除先落地」原則），這裡的 storage
                // 實體清理只是收尾動作，失敗不能讓這次上傳請求回報的原始例外被蓋掉，
                // 也不影響「這批檔案已經不算數」這個已經生效的事實：失敗只留下
                // tombstone，交由 purgeTrashedPhotos()（vehicle-photos:purge-trashed
                // 指令）之後重試。
                try {
                    $this->finalizePhysicalDeletion($photo);
                } catch (\Throwable $cleanupException) {
                    Log::warning('車輛照片上傳失敗回滾時 storage 清理失敗，留下 tombstone 待重試', [
                        'vehicle_photo_id' => $photo->id,
                        'vehicle_id' => $vehicle->id,
                        'exception' => $cleanupException->getMessage(),
                    ]);
                }
            }

            throw $e;
        }

        // 全部檔案都處理完成：batch.photo_ids 已經跟著每個檔案同步更新到位，長度會
        // 剛好等於這次請求的檔案總數，之後 beginUploadBatch() 會依此判斷為「已完成」
        // 直接回放，不需要另外標記完成狀態。曝光（upload_batch_id 清成 NULL）與
        // 封面指定已經在上面迴圈處理最後一個檔案的那個 transaction 內原子性完成
        // （見 finalizeBatchVisibility() 呼叫點），這裡不需要也不應該再重複呼叫一次
        // 帶有封面判斷副作用的版本，避免跟已經在鎖內做過一次的判斷重複競爭。

        // 順手清空租約，避免已完成的 row 留著一個之後不會再被檢查、但語意上已經
        // 沒有意義的到期時間。
        $this->clearProcessingLease($batch, $claimToken);

        // $created 裡的 model 實例是逐檔建立當下的記憶體快照：finalizeBatchVisibility()
        // 對最後一個檔案曝光/指定封面時，是透過另一個 query builder／model 實例
        // 直接寫 DB，不會回頭同步更新這裡持有的 $photo 物件，回傳前必須重新從 DB
        // 讀一次，否則呼叫端看到的 upload_batch_id／is_cover 會是曝光、指定封面
        // 之前的過期值。
        $result = $this->photosInOrder(array_map(fn (VehiclePhoto $photo) => $photo->id, $created));

        // 記稽核紀錄時刻意用「DB 裡是否已經有這張照片的 created 紀錄」判斷，而不是
        // 只看 $newlyCreatedThisCall（這次呼叫自己記憶體裡建立的照片）：resume 模式
        // 下 $alreadyDonePhotos 是前一次呼叫已經真的 commit 到 DB 的照片，但那次呼叫
        // 有可能在跑到這裡（函式最後面）之前就被中止，導致它們從未被記錄過。若只
        // 檢查這次呼叫新建立的部分，這些 resume 帶進來的照片會永久沒有稽核紀錄
        // （Codex stop-time review 指出：partial upload resume 可能讓照片曝光卻沒有
        // created 稽核紀錄）。用 DB 查詢判斷則不論是這次呼叫新建立、還是前一次呼叫
        // 建立但沒記到、或多次 resume 疊加，都能補上唯一一筆紀錄，且對已經記過的
        // 照片是安全的空操作。
        //
        // 「查詢是否已記錄」與「沒有就補插入」這兩步包在同一個、由 lockForUpdate()
        // 鎖住這台車 row 的 transaction 內完成，不能在 transaction 外面單純呼叫。
        // 若不鎖，這次呼叫跟另一個幾乎同時抵達、對同一批照片做 replay 的並發請求
        // 可能都在對方插入之前完成查詢、都判斷「還沒有紀錄」，最終各自补插入一筆
        // 重複的 created 稽核紀錄（Codex stop-time review 指出：concurrent replay
    // 可能重複補寫上傳稽核紀錄）。
        DB::transaction(function () use ($vehicle, $result) {
            Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $this->auditLogMissingUploadEvents($result);
        });

        return $result;
    }

    /**
     * 對集合裡「DB 中還沒有任何 created 稽核紀錄」的照片各補記一筆。用來覆蓋
     * uploadPhotos() 的 resume／replay 路徑：建立照片的那次呼叫與最終把稽核紀錄
     * 補齊的那次呼叫，可能是完全不同的 PHP process（前一次呼叫在寫入稽核紀錄之前
     * 就被中止），單純用記憶體裡「這次呼叫是否新建立」判斷無法涵蓋這種情況，必須
     * 實際查 DB 才能知道哪些照片真的還沒有紀錄。
     *
     * 呼叫端必須已經在同一個 DB transaction 內用 lockForUpdate() 鎖住對應車輛的
     * row，確保「查詢是否已記錄」與「沒有就補插入」對同一台車的並發呼叫是序列化
     * 的，這個方法本身不開自己的 transaction（見兩處呼叫點的說明）。
     *
     * @param  Collection<int, VehiclePhoto>  $photos
     */
    private function auditLogMissingUploadEvents(Collection $photos): void
    {
        if ($photos->isEmpty()) {
            return;
        }

        $photoIds = $photos->map(fn (VehiclePhoto $photo) => $photo->id)->all();

        $alreadyLoggedIds = AuditLog::query()
            ->where('subject_type', 'vehicle_photo')
            ->where('action', AuditLog::ACTION_CREATED)
            ->whereIn('subject_id', $photoIds)
            ->pluck('subject_id')
            ->all();

        $missingPhotos = $photos->filter(
            fn (VehiclePhoto $photo) => ! in_array($photo->id, $alreadyLoggedIds, true)
        );

        if ($missingPhotos->isEmpty()) {
            return;
        }

        // 這裡是補記「不是這次請求本人做的」歷史事件：真正建立這張照片的請求可能
        // 是完全不同的 PHP process、甚至是不同使用者（例如另一個 admin/manager
        // 重打同一把 idempotency_key 觸發 replay）。稽核紀錄的 actor 必須是真正
        // 上傳當下記錄在 uploaded_by 的那個人，不能讓 recordModelEvent() 預設抓
        // Auth::user()，否則會把 created 稽核紀錄的負責人蓋成這次來 replay 的人
        // （Codex adversarial review 指出）。
        $uploaderIds = $missingPhotos->pluck('uploaded_by')->filter()->unique()->all();
        $uploadersById = User::query()->whereIn('id', $uploaderIds)->get()->keyBy('id');

        foreach ($missingPhotos as $photo) {
            $uploader = $photo->uploaded_by !== null ? $uploadersById->get($photo->uploaded_by) : null;

            $this->auditLogService->recordModelEvent($photo, AuditLog::ACTION_CREATED, actor: $uploader);
        }
    }

    /**
     * @param  array<int, int>  $photoIds
     * @return Collection<int, VehiclePhoto>
     */
    private function photosInOrder(array $photoIds): Collection
    {
        if ($photoIds === []) {
            return new Collection([]);
        }

        $photosById = VehiclePhoto::query()->whereIn('id', $photoIds)->get()->keyBy('id');

        // 依 photo_ids 記錄的順序回傳，而不是資料庫預設順序，讓回放/續傳的結果跟
        // 原本上傳的檔案順序一致。理論上這些照片不應該消失（車輛照片刪除是使用者
        // 明確操作，不是這個機制的正常路徑），但仍用 filter 排除任何已經不存在的
        // id，避免對已刪除的照片假造結果。
        return new Collection(array_values(array_filter(
            array_map(fn (int $id) => $photosById->get($id), $photoIds)
        )));
    }

    /**
     * 把這批照片的 upload_batch_id 清成 NULL，視為「已提交、正式可見」（見
     * VehiclePhoto::scopeVisible()），並在此刻（而非個別檔案建立當下）判斷是否
     * 需要幫這批照片指定封面。呼叫端必須已經在同一個 DB transaction 內鎖住這台
     * 車輛的 row（見 uploadPhotos() 兩處呼叫點），確保「曝光」與「封面判斷」跟
     * 同一台車其他並發請求（另一批上傳、deletePhoto()、setCover() 等）序列化，
     * 不會看到彼此中途的狀態。
     *
     * 刻意用一個獨立、可重複呼叫、冪等的方法：整批上傳成功收尾時呼叫一次（在最後
     * 一個檔案的 transaction 內），之後每次 replay 回放時也再呼叫一次自我修復
     * （見 uploadPhotos() 兩處呼叫點的說明）——不管是哪一次呼叫真正讓照片曝光，
     * 結果都一樣，重複呼叫在照片已經可見、已經有封面時是沒有副作用的空操作。
     *
     * 封面刻意留到這裡才判斷，而不是在個別檔案建立當下查「這台車是否已有
     * is_cover=true 的 row」：後者的查詢範圍如果包含其他批次還在中途、尚未提交的
     * hidden 照片，會出現「先佔走封面的那個批次後來失敗 rollback 或被判定放棄而
     * sweep 掉，這批照片卻已經因為誤判『已有封面』而全部是 is_cover=false」的
     * 情況，導致整台車最終沒有任何可見封面（Codex adversarial review 指出）。
     * 這裡只看「此刻已經正式可見的照片裡是否有封面」，只有真的沒有時，才從這批
     * 剛曝光的照片裡挑排序最前面的一張指定為封面。
     *
     * @param  array<int, int>  $photoIds
     */
    private function finalizeBatchVisibility(Vehicle $vehicle, array $photoIds): void
    {
        if ($photoIds === []) {
            return;
        }

        VehiclePhoto::query()->whereIn('id', $photoIds)->update(['upload_batch_id' => null]);

        $hasVisibleCover = VehiclePhoto::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNull('upload_batch_id')
            ->where('is_cover', true)
            ->exists();

        if ($hasVisibleCover) {
            return;
        }

        // cover_slot 是不分可見／隱藏、也不分是否已 soft-delete 的 DB-wide unique
        // 索引（見 2026_07_09_000000 migration 說明：virtual column 直接算在實體
        // row 上，完全不理解 Laravel 的 soft-delete scope），只要這台車有任何一筆
        // row 帶著 is_cover=true，不論它是否可見、是否已被邏輯刪除，都會佔用這台
        // 車唯一的 cover_slot 值，直接寫入新封面會撞到這個 unique constraint 而讓
        // 整個曝光 transaction 失敗。理論上這次修正之後，任何照片建立當下一律先是
        // is_cover=false，只有這裡才會指定封面，不該再有隱藏或已刪除的 row 帶著
        // is_cover=true；但這次修正上線前建立的殘留資料，或其他尚未預見的路徑，
        // 仍可能留下這種不一致狀態（Codex stop-time review 兩輪分別指出：先是
        // 「只檢查可見封面是否存在不夠，隱藏的殘留封面 row 仍會讓這裡的寫入直接
        // 炸掉」，接著指出「清除殘留封面時沒有用 withTrashed()，Eloquent 預設
        // scope 會排除掉 soft-deleted row，讓已經被邏輯刪除、但物理上仍佔用
        // cover_slot 的殘留 row 沒被清到，一樣會讓寫入直接炸掉」）。上面已經確認
        // 沒有任何可見封面，代表這台車此刻「合法」的封面狀態就是沒有封面，此時
        // 若還有 is_cover=true 的 row（不論可不可見、有沒有被邏輯刪除），一定是
        // 不該存在的殘留資料，直接清掉讓 cover_slot 空出來，順便自我修復這筆
        // 髒資料。
        VehiclePhoto::query()
            ->withTrashed()
            ->where('vehicle_id', $vehicle->id)
            ->where('is_cover', true)
            ->update(['is_cover' => false]);

        $coverCandidate = VehiclePhoto::query()
            ->whereIn('id', $photoIds)
            ->whereNull('upload_batch_id')
            ->orderBy('sort_order')
            ->first();

        if ($coverCandidate !== null) {
            $coverCandidate->is_cover = true;
            $coverCandidate->save();
        }
    }

    private function clearProcessingLease(VehiclePhotoUploadBatch $batch, string $claimToken): void
    {
        try {
            $this->applyBatchUpdateIfOwned($batch, $claimToken, ['processing_lease_expires_at' => null]);
        } catch (\Throwable $e) {
            // 只是收尾的衛生動作（見呼叫端註解），失敗不影響這次請求本身的結果：
            // 最壞情況只是下一次同把 key 的請求要多等到租約自然到期，不會遺失任何
            // 已經建立的照片或 batch 進度，記錄下來即可，不需要往外拋。這裡不特別
            // 區分「更新失敗」與「fencing token 不符（已被取代）」：兩者對這個已經
            // 完全成功的請求而言後果相同，都只是少做一個之後不會再被檢查的收尾動作。
            Log::warning('車輛照片上傳批次清空 processing lease 失敗', [
                'vehicle_id' => $batch->vehicle_id,
                'idempotency_key' => $batch->idempotency_key,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 用 claim_token 當作 fencing token 寫入 batch：只有 $claimToken 與資料庫目前
     * 記錄的 claim_token 相符（代表呼叫端此刻仍然是這筆 batch 真正的擁有者）才會
     * 真的寫入並回傳 true；一旦這個 batch 已經被另一個請求續傳認領（核發了新的
     * claim_token），這裡會回傳 false，且完全不寫入任何欄位——呼叫端必須自行決定
     * 如何回應「已被取代」這件事，不能假裝寫入成功（Codex adversarial review 第四輪
     * 指出：先前版本只在認領當下核發新租約，卻沒有在認領之後的每一次寫入都驗證
     * 擁有權）。
     *
     * 這裡刻意繞過 Model::update()，改用 query builder 直接下 `WHERE id = ? AND
     * claim_token = ?` 當作 fencing 條件，因為 Eloquent 的 Model::update() 只會用
     * 主鍵當 WHERE 條件，無法在同一次 UPDATE 語句內原子性地附加這個額外條件。寫入
     * 成功後才把同樣的值同步進記憶體中的 $batch 物件，讓呼叫端後續讀取
     * $batch->photo_ids 等屬性時看到的是最新值。
     *
     * @param  array<string, mixed>  $attributes  Model 層級的屬性值（例如 PHP array、
     *                                            Carbon 或 null），會在這裡自行轉換成 query builder 需要的儲存格式。
     */
    private function applyBatchUpdateIfOwned(VehiclePhotoUploadBatch $batch, string $claimToken, array $attributes): bool
    {
        $storageAttributes = [];
        foreach ($attributes as $key => $value) {
            $storageAttributes[$key] = match (true) {
                is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
                $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => $value,
            };
        }

        $affected = VehiclePhotoUploadBatch::query()
            ->whereKey($batch->id)
            ->where('claim_token', $claimToken)
            ->update($storageAttributes);

        if ($affected !== 1) {
            return false;
        }

        foreach ($attributes as $key => $value) {
            $batch->setAttribute($key, $value);
        }

        return true;
    }

    /**
     * uploadPhotos() 失敗回滾時使用：在同一個 DB transaction 內，用 claim_token
     * fencing 把 batch.photo_ids／租約復原回 $alreadyDoneIds，並把這次呼叫自己
     * 建立、現在要回滾的照片全部 soft-delete。回傳 false 代表 fencing 檢查失敗
     * （已被另一個請求續傳認領），這種情況下整個 transaction 會 rollback、什麼都
     * 不會發生——不會復原 batch，也不會刪除任何照片，呼叫端必須自行決定如何回應
     * 「已被取代」這件事。
     *
     * 把「batch 復原」與「照片 soft-delete」包在同一個 transaction，是為了避免
     * 先復原 batch 再刪除照片這種兩步驟做法留下的時間窗：一旦 batch 復原先
     * commit，另一個帶著同一把 idempotency_key 的請求就可能立刻認領、重新處理
     * 這幾個檔案，此時舊照片可能還「可見」（尚未刪除），造成短暫但真實的重複
     * 可見視窗；若之後 storage 清理又失敗，這些孤兒照片甚至會永久留著（Codex
     * adversarial review 第六輪指出）。這裡改成先 soft-delete（跟 deletePhoto()
     * 相同的「邏輯刪除先落地」原則），一旦這個 transaction commit，這些照片立刻
     * 從所有查詢（含每台車照片上限計算、封面判斷）消失，之後才由呼叫端另外做
     * storage 實體清理（best-effort，失敗只留下 tombstone，交由
     * purgeTrashedPhotos() 之後重試）。
     *
     * 這個 transaction 帶了 3 次自動重試（`DB::transaction($callback, 3)`）：
     * lockForUpdate() 在高併發下可能撞上 deadlock 或短暫的 lock wait timeout，
     * Laravel 對這類可重試的 QueryException 會自動整個 closure 重跑，不需要自己
     * 手刻重試迴圈。若 3 次都失敗，例外會直接往外拋，由呼叫端（uploadPhotos()）
     * 決定如何回應（見那裡的 try/catch）。
     *
     * 刻意宣告成 protected 而非 private：測試需要模擬「這個 transaction 重試 3
     * 次後仍然失敗」這個情境，用匿名子類別覆寫這個方法丟出例外是唯一不需要真的
     * 製造一次可攜（SQLite/MySQL 都能重現）的底層 DB 失敗、就能可靠測到
     * uploadPhotos() 對應 catch 分支的方式；private 方法無法被子類別覆寫。
     *
     * @param  array<int, int>  $alreadyDoneIds
     * @param  array<int, VehiclePhoto>  $newlyCreatedThisCall
     */
    protected function restoreBatchAndSoftDeleteRolledBackPhotos(
        Vehicle $vehicle,
        VehiclePhotoUploadBatch $batch,
        string $claimToken,
        array $alreadyDoneIds,
        array $newlyCreatedThisCall,
    ): bool {
        return DB::transaction(function () use ($vehicle, $batch, $claimToken, $alreadyDoneIds, $newlyCreatedThisCall) {
            Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

            $owned = $this->applyBatchUpdateIfOwned($batch, $claimToken, [
                'photo_ids' => $alreadyDoneIds,
                'processing_lease_expires_at' => null,
            ]);

            if (! $owned) {
                return false;
            }

            if ($newlyCreatedThisCall === []) {
                return true;
            }

            $rolledBackIds = array_map(fn (VehiclePhoto $photo) => $photo->id, $newlyCreatedThisCall);

            // cover_slot 的 DB unique 索引不理解 Laravel 的 soft-delete scope，即使
            // 等一下把這幾筆 row soft-delete，物理上它們仍然存在、is_cover 仍是
            // true 就仍佔用這台車的 cover_slot 唯一值。必須先明確清成 false，才能
            // 把封面指定給下一張仍然有效的照片，否則會撞到唯一索引（沿用
            // deletePhoto() 同一套處理方式）。
            $wasCoverAmongRolledBack = VehiclePhoto::query()
                ->whereIn('id', $rolledBackIds)
                ->where('is_cover', true)
                ->exists();

            if ($wasCoverAmongRolledBack) {
                VehiclePhoto::query()->whereIn('id', $rolledBackIds)->update(['is_cover' => false]);
            }

            VehiclePhoto::query()->whereIn('id', $rolledBackIds)->delete();

            if ($wasCoverAmongRolledBack) {
                $next = $vehicle->photos()->orderBy('sort_order')->first();
                if ($next !== null) {
                    $next->is_cover = true;
                    $next->save();
                }
            }

            return true;
        }, 3);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{vehicle_id: int, files: array<int, array{sha256: string, size: int, original_filename: string}>}
     */
    private function buildUploadPayload(Vehicle $vehicle, array $files): array
    {
        return [
            'vehicle_id' => $vehicle->id,
            'files' => array_map(fn (UploadedFile $file) => [
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'size' => $file->getSize(),
                'original_filename' => $file->getClientOriginalName(),
            ], $files),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{batch: VehiclePhotoUploadBatch, mode: 'new'|'resume'|'replay', claimToken: string}
     */
    private function beginUploadBatch(Vehicle $vehicle, string $idempotencyKey, array $payload): array
    {
        $leaseSeconds = (int) config('vehicle_photos.upload_batch_pending_ttl_seconds');

        try {
            $claimToken = (string) Str::uuid();

            $batch = DB::transaction(fn () => VehiclePhotoUploadBatch::create([
                'vehicle_id' => $vehicle->id,
                'idempotency_key' => $idempotencyKey,
                'idempotency_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'photo_ids' => [],
                'processing_lease_expires_at' => now()->addSeconds($leaseSeconds),
                'claim_token' => $claimToken,
            ]));

            return ['batch' => $batch, 'mode' => 'new', 'claimToken' => $claimToken];
        } catch (QueryException $e) {
            if (! $this->isIdempotencyKeyUniqueViolation($e)) {
                throw $e;
            }

            // 沿用 VehicleService 既有的 race pattern：unique 違反後 rollback，開新
            // transaction 並 lockForUpdate 重新讀取真正贏得這把 key 的 row，避免在同一個
            // 已經失敗的 transaction 裡繼續讀取。
            return DB::transaction(function () use ($vehicle, $idempotencyKey, $payload, $leaseSeconds) {
                $existing = VehiclePhotoUploadBatch::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    // 造成 unique 違反的那筆 row，在我們的 INSERT 失敗到現在重新查詢
                    // 這段時間內已經不存在了。最可能的原因是
                    // vehicle-photos:sweep-stale-uploads 排程判定它已經永久放棄並清理
                    // 掉（見 abandonStaleIncompleteUploadBatches()）——代表這把
                    // idempotency_key 事實上已經空出來了，不是真的有另一個目前仍然
                    // 存在的 row 在跟我們搶。若直接把原始的 unique constraint
                    // QueryException 往外拋，一個合法的重試會收到一個難以理解的底層
                    // SQL 錯誤，而不是乾淨地繼續完成上傳（Codex adversarial review 第
                    // 十一輪指出）。這裡改成把這次請求當成全新的認領，在同一個
                    // transaction 內重新 INSERT 一次；如果連這次都又剛好撞上另一個
                    // 幾乎同一時間抵達的請求（極端的雙重競態），才把那次的 unique
                    // constraint 例外原封不動往外拋，不再進一步重試。
                    $retryClaimToken = (string) Str::uuid();

                    $batch = VehiclePhotoUploadBatch::create([
                        'vehicle_id' => $vehicle->id,
                        'idempotency_key' => $idempotencyKey,
                        'idempotency_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'photo_ids' => [],
                        'processing_lease_expires_at' => now()->addSeconds($leaseSeconds),
                        'claim_token' => $retryClaimToken,
                    ]);

                    return ['batch' => $batch, 'mode' => 'new', 'claimToken' => $retryClaimToken];
                }

                $storedPayload = json_decode((string) $existing->idempotency_payload, true);
                $payloadMatches = is_array($storedPayload) && $storedPayload === $payload;

                if (! $payloadMatches) {
                    // 內容不同一律拒絕：即使這把 key 目前還沒完成、甚至根本還沒開始
                    // 處理，也不能被拿來冒用成另一批完全不同的檔案。
                    throw ValidationException::withMessages([
                        'idempotency_key' => '此 idempotency_key 已用於內容不同的照片上傳請求。',
                    ]);
                }

                $doneCount = count($existing->photo_ids ?? []);
                $totalCount = count($payload['files']);

                if ($doneCount >= $totalCount) {
                    // 已完成的批次（photo_ids 長度已達檔案總數）：直接回放，不需要
                    // claim_token（不會再寫入任何東西）。
                    return ['batch' => $existing, 'mode' => 'replay', 'claimToken' => (string) $existing->claim_token];
                }

                // 尚未完成，可能是 (a) 真的還在處理中（另一個幾乎同時抵達的相同請求，
                // 或同一個請求自己還沒跑完），也可能是 (b) 前一次處理程序被中止（worker
                // 被強制中止、fatal error、伺服器重啟）留下的殘留租約，永遠不會有人把
                // 它跑完（Codex adversarial review 指出：若不處理 (b)，這把 key 會
                // 永久卡死，需要人工介入資料庫才能恢復）。用 processing_lease_expires_at
                // 是否仍在未來區分兩者：租約有效一律保守視為 (a) 拒絕；租約已過期（或
                // 從未設定）才視為 (b) 放棄的殘留，予以續傳認領。
                //
                // 租約只在「我們自己的 PHP 呼叫確定結束」時才會被明確清空（見
                // uploadPhotos() 成功/失敗兩條路徑的 clearProcessingLease()），因此
                // 「租約已過期」精準對應「前一個擁有者已經真的不在了」，不會誤判成
                // 「還在跑、只是剛好快超時」——那種情況下租約仍在未來，會落在下面的
                // else 分支被保守拒絕。
                $leaseExpired = $existing->processing_lease_expires_at === null
                    || $existing->processing_lease_expires_at->isPast();

                if (! $leaseExpired) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => '此照片上傳仍在處理中，請稍後再試一次。',
                    ]);
                }

                Log::warning('車輛照片上傳批次租約已過期，視為前一次處理程序已放棄並續傳認領', [
                    'vehicle_id' => $vehicle->id,
                    'idempotency_key' => $idempotencyKey,
                    'batch_id' => $existing->id,
                    'done_count' => $doneCount,
                    'total_count' => $totalCount,
                    'previous_lease_expires_at' => $existing->processing_lease_expires_at?->toIso8601String(),
                ]);

                // 續傳認領：核發一把新的 claim_token 並重新核發租約，photo_ids／
                // idempotency_payload 完全不動，讓 uploadPhotos() 從 $doneCount 之後
                // 接著處理剩下的檔案，不重做已經真的建立過照片的部分。這個 update()
                // 發生在 lockForUpdate() 鎖住這筆 row 的同一個 transaction 內，
                // 確保「認領」這個動作本身不會跟另一個同時嘗試續傳的請求競爭——
                // 兩者會依 lockForUpdate 序列化，第二個進來時會看到租約已經被剛剛
                // 那個請求重新核發成未過期，落回上面的 else 分支被保守拒絕。
                //
                // 核發新 claim_token 是這裡真正的關鍵：前一個擁有者若其實只是跑得
                // 比租約久、尚未真正放棄（例如主機嚴重過載導致還在處理中的請求遲遲
                // 無法呼叫 clearProcessingLease()），它手上握著的仍是舊的
                // claim_token。之後它每一次嘗試寫入 photo_ids 或
                // processing_lease_expires_at，都會因為 applyBatchUpdateIfOwned()
                // 的 fencing 檢查失敗而被擋下（見 uploadPhotos()），不會覆蓋掉這次
                // 續傳認領之後寫入的新進度，也不會誤刪已經回傳給使用者的照片
                // （Codex adversarial review 第四輪指出：先前版本只重新核發租約，
                // 沒有這層 fencing，被取代的舊請求仍可能繼續寫壞新請求的進度）。
                $claimToken = (string) Str::uuid();
                $existing->update([
                    'processing_lease_expires_at' => now()->addSeconds($leaseSeconds),
                    'claim_token' => $claimToken,
                ]);

                return ['batch' => $existing, 'mode' => 'resume', 'claimToken' => $claimToken];
            });
        }
    }

    private function isIdempotencyKeyUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        if ($sqlState !== '23000') {
            return false;
        }

        return str_contains($e->getMessage(), 'idempotency_key');
    }

    public function deletePhoto(Vehicle $vehicle, VehiclePhoto $photo): void
    {
        $this->assertBelongsToVehicle($vehicle, $photo);

        // DB 狀態變更與 storage 實體刪除無法跨系統原子化，先做哪一邊都各自有風險
        // （兩輪 Codex adversarial review 分別指出：先刪 DB row 會讓「已刪除」的照片
        // 在 storage 清乾淨前仍可公開存取；先刪 storage 則會在處理中途當機/逾時時，
        // 讓 DB row 永久卡在指向不存在檔案的壞掉狀態，且無法重試）。
        //
        // 解法是用 deleted_at soft delete 把這兩個風險都消掉：先在鎖定車輛 row 的
        // 同一個 transaction 內把這張照片標記為 soft-deleted（同時清空 is_cover、
        // 改指定下一張封面）。一旦這個 transaction commit，此照片立刻從所有查詢
        // （含 public API、列表、封面判斷）消失，不會再有「DB 說已刪除、storage
        // 還在服務」的公開存取視窗。commit 之後才進行 storage 實體刪除；若那一步
        // 失敗或程序中斷，DB row 仍完整保留 disk/path/thumbnail_path 且已經是
        // 對外隱藏狀態，之後可以安全重試 storage 清理而不影響任何讀取行為。只有
        // storage 確定清乾淨後，才 forceDelete() 徹底移除這筆 tombstone row。
        DB::transaction(function () use ($vehicle, $photo) {
            Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

            // $photo 是進入 transaction 前、拿到 row lock 前就載入的實例，若在「載入」
            // 與「拿到 lock」之間有另一個並發請求呼叫 setCover() 把封面換成這張照片，
            // 這裡的 $photo->is_cover 會是過期的 false，導致以為刪除的不是封面、
            // 不去補封面，讓車輛最終沒有任何封面照（Codex adversarial review 指出）。
            // 拿到 lock 後必須重新從 DB 讀一次目前真正的 is_cover 狀態。若照片在拿到
            // lock 前已被另一個並發請求刪除，refresh() 會直接拋 ModelNotFoundException，
            // 不會誤判成「不是封面」而靜默略過補封面。
            $photo->refresh();
            $wasCover = $photo->is_cover;

            if ($wasCover) {
                // cover_slot 的 DB unique 索引不理解 Laravel 的 soft-delete scope，
                // 即使等一下把這筆 row soft-delete，物理上它仍然存在、is_cover 仍是
                // true 就仍佔用這台車的 cover_slot 唯一值。必須先明確清成 false 並
                // 存檔，才能把封面指定給下一張照片，否則會撞到唯一索引。
                $photo->is_cover = false;
                $photo->save();
            }

            $photo->delete();

            if ($wasCover) {
                $next = $vehicle->photos()->orderBy('sort_order')->first();
                if ($next !== null) {
                    $next->is_cover = true;
                    $next->save();
                }
            }
        });

        // 稽核紀錄放在上面 transaction commit 之後、storage 實體清理之前：對使用者
        // 而言「刪除」在 DB transaction commit 那一刻就已經真正生效，此時 $photo
        // 已經是 soft-deleted 之後的狀態（refresh() 過的最新 is_cover/deleted_at），
        // 不需要等 storage 清理這個 best-effort 收尾動作完成才記錄。
        $this->auditLogService->recordModelEvent($photo, AuditLog::ACTION_DELETED);

        // 對使用者而言，「刪除」這個動作在上面的 transaction commit 那一刻就已經
        // 真正、永久生效：這張照片從此不會出現在任何查詢、任何 API 回應中。這裡的
        // storage 實體清理只是收尾動作，失敗時不能讓呼叫端看到「刪除失敗」——因為
        // 那是一句謊言：邏輯刪除早已成功且不可逆，使用者能觀察到的所有行為都已經
        // 是「這張照片不存在了」。若把這裡的例外往外拋，呼叫端唯一能做的「重試」
        // 就是再打一次同樣的 DELETE API，但 route model binding 預設排除
        // soft-deleted row，那個請求只會拿到 404，永遠無法真的完成清理，等於把一個
        // 已經成功的操作包裝成一個無法透過正常 API 重試修復的失敗（Codex
        // adversarial review 指出）。因此這裡吞下例外：storage 清理失敗只留下
        // tombstone row，交由 purgeTrashedPhotos()（vehicle-photos:purge-trashed
        // 指令）之後重試，不影響這次刪除請求本身回報成功。
        try {
            $this->finalizePhysicalDeletion($photo);
        } catch (\Throwable $e) {
            // 不往外拋（見上方註解），但仍記錄下來，避免 tombstone 累積卻完全沒人
            // 知道，需要靠人工執行 vehicle-photos:purge-trashed 才會發現。
            Log::warning('車輛照片 storage 清理失敗，留下 tombstone 待重試', [
                'vehicle_photo_id' => $photo->id,
                'disk' => $photo->disk,
                'path' => $photo->path,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 掃描並重試因 storage 清理失敗（或處理中途中斷）而卡在 soft-deleted
     * tombstone 狀態的車輛照片。這是 deletePhoto() 內 finalizePhysicalDeletion()
     * 失敗後唯一的重試管道：這些照片的 DB row 已經 soft-deleted、對外完全隱藏，
     * 不會出現在任何一般查詢或 route model binding 結果中，只能靠直接掃描
     * onlyTrashed() 才找得到。適合排進排程或由管理者手動執行（見
     * `App\Console\Commands\PurgeTrashedVehiclePhotosCommand`）。
     *
     * @return array{purged: int, failed: int}
     */
    public function purgeTrashedPhotos(): array
    {
        $purged = 0;
        $failed = 0;

        VehiclePhoto::onlyTrashed()->get()->each(function (VehiclePhoto $photo) use (&$purged, &$failed) {
            try {
                $this->finalizePhysicalDeletion($photo);
                $purged++;
            } catch (\Throwable $e) {
                $failed++;

                // 這個方法是排程（vehicle-photos:purge-trashed，見 routes/console.php）
                // 唯一的重試路徑，一旦排在背景無人值守跑，指令輸出的 purged=/failed=
                // 統計數字沒有人會即時盯著看。若這裡不記錄是哪一筆、哪個 disk/path、
                // 什麼原因失敗，storage 權限或連線退化時，公開網址指向的檔案可能無限
                // 期留著，卻沒有任何可行動的線索能查是哪張照片、為什麼卡住（Codex
                // adversarial review 指出）。
                Log::warning('車輛照片 tombstone 重試清理仍然失敗', [
                    'vehicle_photo_id' => $photo->id,
                    'vehicle_id' => $photo->vehicle_id,
                    'disk' => $photo->disk,
                    'path' => $photo->path,
                    'thumbnail_path' => $photo->thumbnail_path,
                    'exception' => $e->getMessage(),
                ]);
            }
        });

        return ['purged' => $purged, 'failed' => $failed];
    }

    /**
     * 掃描並清理長期沒有任何請求再回來續傳的未完成上傳批次。
     * upload_batch_pending_ttl_seconds 過期只代表「允許下一個真實請求續傳認領」，
     * 不保證真的會有請求回來——如果使用者放棄重試（例如直接關掉分頁），這批上傳
     * 裡已經真的建立好的照片會一直以正常、可見的 VehiclePhoto row 留著，讓一次
     * 從未真正完成的上傳看起來像是成功上傳了一部分（Codex adversarial review 第
     * 八輪指出）。
     *
     * 判斷「永久放棄」用 updated_at 是否早於 upload_batch_abandon_sweep_seconds
     * 門檻（見 config/vehicle_photos.php 的說明，預設遠大於
     * upload_batch_pending_ttl_seconds），而不是只看 processing_lease_expires_at
     * 是否早於門檻——租約在 uploadPhotos() 一般失敗的復原路徑（見
     * restoreBatchAndSoftDeleteRolledBackPhotos()）與整批成功後都會被明確清成
     * `null`，代表「立即可續傳」，但這不等於「已經放棄」：一個 photo_ids 仍未達
     * 檔案總數、租約是 `null` 的批次，可能是「剛失敗、幾秒後就會被使用者重試」，
     * 也可能是「使用者當下就直接放棄、從此再也沒人回來」，兩者從
     * processing_lease_expires_at 這個欄位本身完全無法區分（Codex adversarial
     * review 第十輪指出：先前版本的候選查詢要求 processing_lease_expires_at 不為
     * null 且早於門檻，會讓所有租約已清空的未完成批次永遠不會被掃到、永久跳過
     * 清理）。改用 updated_at（任何一次有意義的寫入——認領、逐檔進度更新、清空
     * 租約——都會更新這個欄位）當作「距離上次真的有人動過這筆批次多久了」的
     * 唯一判準，不管租約當下是 `null` 還是一個過期的舊時間戳，都能正確反映出
     * 「已經放置多久沒人碰」。額外用「租約不是仍在未來的有效值」擋掉真正有人
     * 正在處理中的批次，確保不會誤傷。符合門檻的批次會把目前 photo_ids 記錄的
     * 照片全部 soft-delete（沿用 deletePhoto() 同一套 cover_slot 處理方式），
     * 並直接刪除這筆 batch row——沒有原始檔案可以繼續處理，這筆記錄除了佔位
     * 沒有其他用途，刪除後也讓同一把 idempotency_key 未來能被當成全新的上傳
     * 使用。soft-delete 之後的 storage 實體清理交給既有的
     * purgeTrashedPhotos()（vehicle-photos:purge-trashed 排程）處理，不在這裡
     * 重複實作。適合排進排程或由管理者手動執行（見
     * `App\Console\Commands\SweepStaleVehiclePhotoUploadBatchesCommand`）。
     *
     * @return array{abandoned: int, failed: int}
     */
    public function abandonStaleIncompleteUploadBatches(): array
    {
        $abandoned = 0;
        $failed = 0;
        $skipped = 0;

        $sweepSeconds = (int) config('vehicle_photos.upload_batch_abandon_sweep_seconds');
        $cutoff = now()->subSeconds($sweepSeconds);

        $candidateIds = VehiclePhotoUploadBatch::query()
            ->where('updated_at', '<', $cutoff)
            ->where(function ($query) {
                // 租約是 null（一般失敗復原或整批成功後清空）或已過期，都代表此刻
                // 沒有人「正在」處理中；真正在處理的批次一定有一個仍在未來的租約，
                // 這裡先排除掉，避免候選清單掃到還在合法進行中的批次（實際的
                // 放棄判斷仍在 reclaimIfStillAbandoned() 內鎖定後重新檢查一次）。
                $query->whereNull('processing_lease_expires_at')
                    ->orWhere('processing_lease_expires_at', '<', now());
            })
            ->pluck('id');

        $candidateIds->each(function (int $batchId) use ($cutoff, &$abandoned, &$failed, &$skipped) {
            try {
                $outcome = $this->reclaimIfStillAbandoned($batchId, $cutoff);

                if ($outcome === 'abandoned') {
                    $abandoned++;

                    Log::warning('車輛照片上傳批次長期無人續傳，視為永久放棄並清理殘留照片', [
                        'batch_id' => $batchId,
                    ]);
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;

                // 這個方法是排程（vehicle-photos:sweep-stale-uploads，見
                // routes/console.php）唯一的清理路徑，一旦排在背景無人值守跑，
                // 指令輸出的統計數字沒有人會即時盯著看。若這裡不記錄是哪一筆、
                // 什麼原因失敗，殘留照片可能無限期留著卻沒有任何可行動的線索。
                Log::warning('車輛照片上傳批次長期無人續傳，清理失敗，將於下次排程重試', [
                    'batch_id' => $batchId,
                    'exception' => $e->getMessage(),
                ]);
            }
        });

        // $skipped（狀態已改變、被真正的重試接手或已完成而放棄清理的批次數）只用於
        // 內部判斷，不放進回傳值：呼叫端（指令、測試）只需要知道「實際清理了幾筆」
        // 與「清理失敗幾筆」，被安全跳過的批次不算失敗，也不是需要額外關注的異常。
        unset($skipped);

        return ['abandoned' => $abandoned, 'failed' => $failed];
    }

    /**
     * abandonStaleIncompleteUploadBatches() 的每一筆候選都交給這裡個別處理，回傳
     * `'abandoned'`（真的清理掉了）或 `'skipped'`（此刻其實已經不符合放棄條件，
     * 什麼都沒做）。
     *
     * 這裡刻意「不」沿用外層候選清單掃描時讀到的快照，而是在同一個 DB transaction
     * 內用 lockForUpdate() 鎖住這筆 row 之後，重新從 DB 讀一次目前真正的狀態：
     * 外層的候選清單是在進入這個 transaction「之前」查出來的，這段時間差裡完全
     * 可能有一個真正的使用者請求帶著同一把 idempotency_key 回來續傳認領，核發
     * 新的租約、繼續處理甚至已經完成整批上傳。如果繼續用外層的舊 photo_ids 快照
     * 去刪照片、刪 batch，會刪掉這個真實請求已經完成、甚至已經回傳給使用者的照片
     * 與紀錄（Codex adversarial review 第九輪指出）。這裡重新檢查「租約是否仍然
     * 早於這次掃描開始時的 cutoff」與「是否仍未完成」，確保只有此刻真的還符合
     * 放棄條件的批次才會被清理；一旦狀態已經改變（被續傳認領、完成，或租約已經
     * 被重新核發），直接回傳 `'skipped'`，不做任何事，留給下一輪排程或使用者自己
     * 的流程處理。
     *
     * 刻意宣告成 protected 而非 private：測試需要模擬「候選清單掃描之後、這個
     * transaction 真正鎖住 row 之前，這筆批次已經被一個真實請求續傳認領或完成」
     * 這個競態窗口，最直接可靠的方式是繞過外層的候選查詢時機、直接針對已經改變
     * 過的狀態呼叫這個方法本身；private 方法無法被子類別或測試以此方式驗證。
     */
    protected function reclaimIfStillAbandoned(int $batchId, Carbon $cutoff): string
    {
        return DB::transaction(function () use ($batchId, $cutoff) {
            $batch = VehiclePhotoUploadBatch::query()->whereKey($batchId)->lockForUpdate()->first();

            if ($batch === null) {
                // 已經被其他排程執行、或前面某次重試順帶清掉了。
                return 'skipped';
            }

            $payload = json_decode((string) $batch->idempotency_payload, true);
            $totalCount = is_array($payload) && isset($payload['files']) && is_array($payload['files'])
                ? count($payload['files'])
                : 0;
            $doneCount = count($batch->photo_ids ?? []);

            // 租約是 null（一般失敗復原或整批成功後清空）或已過期，都代表此刻沒有
            // 人「正在」處理中；一個仍在未來的租約代表真的有人正在合法處理，一律
            // 保留不動。是否「已經放棄」看 updated_at（任何一次有意義的寫入都會
            // 更新這個欄位）是否早於這次掃描開始時的 cutoff，不能只看
            // processing_lease_expires_at 是否早於 cutoff——租約清成 null 之後就
            // 永遠不會再「早於」任何時間點，若只看這個欄位，所有租約已清空的未
            // 完成批次會永久跳過清理（Codex adversarial review 第十輪指出）。
            $leaseIsActive = $batch->processing_lease_expires_at !== null
                && $batch->processing_lease_expires_at->isFuture();

            $stillAbandoned = $doneCount < $totalCount
                && ! $leaseIsActive
                && $batch->updated_at !== null
                && $batch->updated_at->lt($cutoff);

            if (! $stillAbandoned) {
                return 'skipped';
            }

            $vehicle = Vehicle::query()->whereKey($batch->vehicle_id)->lockForUpdate()->first();

            // 理論上不會發生：vehicle_photos.vehicle_id 已經 harden 成 RESTRICT
            // （見 2026_07_09_000001 migration），只要這批照片還沒清乾淨，車輛就
            // 無法被刪除。但 vehicle_photo_upload_batches 自己的 vehicle_id 外鍵
            // 仍是 CASCADE（見該表 migration 說明），車輛真的被刪除時 batch row
            // 會被一起刪掉，理論上不會再被外層查詢掃到；防禦性地處理成直接刪除
            // row，不假設一定找得到車輛。
            if ($vehicle === null) {
                $batch->delete();

                return 'abandoned';
            }

            $photoIds = $batch->photo_ids ?? [];

            if ($photoIds !== []) {
                // cover_slot 的 DB unique 索引不理解 Laravel 的 soft-delete scope，
                // 必須先明確清成 false，才能把封面指定給下一張仍然有效的照片，
                // 否則會撞到唯一索引（沿用 deletePhoto() 同一套處理方式）。
                $wasCoverAmongAbandoned = VehiclePhoto::query()
                    ->whereIn('id', $photoIds)
                    ->where('is_cover', true)
                    ->exists();

                if ($wasCoverAmongAbandoned) {
                    VehiclePhoto::query()->whereIn('id', $photoIds)->update(['is_cover' => false]);
                }

                VehiclePhoto::query()->whereIn('id', $photoIds)->delete();

                if ($wasCoverAmongAbandoned) {
                    $next = $vehicle->photos()->orderBy('sort_order')->first();
                    if ($next !== null) {
                        $next->is_cover = true;
                        $next->save();
                    }
                }
            }

            $batch->delete();

            return 'abandoned';
        }, 3);
    }

    private function finalizePhysicalDeletion(VehiclePhoto $photo): void
    {
        $this->processor->delete($photo->disk, $photo->path, $photo->thumbnail_path);

        $photo->forceDelete();
    }

    public function setCover(Vehicle $vehicle, VehiclePhoto $photo): VehiclePhoto
    {
        $this->assertBelongsToVehicle($vehicle, $photo);

        $originalBeforeSave = null;

        $freshPhoto = DB::transaction(function () use ($vehicle, $photo, &$originalBeforeSave) {
            Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

            // 拿到 lock 前載入的 $photo 可能已經過期：如果這張照片在「載入」與「拿到
            // lock」之間被另一個並發請求刪除，直接對 stale instance 呼叫 save() 只會
            // 靜默更新 0 筆（Eloquent 不會拋錯），導致其他封面已被清空、卻沒有任何
            // 照片被設為新封面，車輛最終沒有封面（Codex adversarial review 指出）。
            // 改成從 vehicle 關聯以 firstOrFail() 重新載入目標照片，確定它此刻仍存在
            // 且屬於這台車，才進行封面切換。
            $freshPhoto = $vehicle->photos()->whereKey($photo->id)->firstOrFail();

            $vehicle->photos()
                ->where('is_cover', true)
                ->where('id', '!=', $freshPhoto->id)
                ->update(['is_cover' => false]);

            $freshPhoto->is_cover = true;

            // 必須在 save() 之前擷取原始值：save() 內部的 finishSave() 會在回傳前
            // 呼叫 syncOriginal()，把 getRawOriginal() 覆寫成剛寫入的新值。若在
            // save() 之後才讀 getRawOriginal() 當作 before_values，會把「換封面
            // 前」誤記成跟「換封面後」一樣，稽核紀錄的 diff 會被寫壞（Codex
            // adversarial review 指出）。
            $originalBeforeSave = $freshPhoto->getRawOriginal();
            $freshPhoto->save();

            return $freshPhoto;
        });

        // getChanges() 是 save() 當下算出來、還沒被 syncOriginal() 影響的欄位清單，
        // 仍然可以拿來跟上面在 save() 之前擷取的 $originalBeforeSave 取交集，算出
        // 正確的 before/after（is_cover 等）。
        $this->auditLogService->recordModelEvent(
            $freshPhoto,
            AuditLog::ACTION_UPDATED,
            originalOverride: $originalBeforeSave,
        );

        return $freshPhoto;
    }

    /**
     * @param  array<int, int>  $photoIds
     */
    public function reorder(Vehicle $vehicle, array $photoIds): Collection
    {
        $submittedIds = array_map('intval', $photoIds);

        $result = DB::transaction(function () use ($vehicle, $submittedIds) {
            Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

            // 鎖定車輛 row 之後才在 transaction 內重新讀取「此刻」這台車的完整照片
            // 清單，並要求提交的 photo_ids 剛好等於這個完整清單（不多不少、不重複），
            // 而不是只驗證「提交的 id 都屬於這台車」。只驗證子集合會讓只送一兩張
            // 照片 id 的請求把這幾筆 sort_order 重寫成從 0 開始，卻不動其餘照片原本
            // 的 sort_order，造成同車輛出現重複的 sort_order、透過 Vehicle::photos()
            // 排序結果變得不確定（Codex adversarial review 指出）。在鎖定後才讀取
            // 目前清單，也避免用 lock 之前、可能已過期的照片集合做驗證。
            $currentPhotos = $vehicle->photos()->get()->keyBy('id');

            $isExactSet = count($submittedIds) === count(array_unique($submittedIds))
                && count($submittedIds) === $currentPhotos->count()
                && array_diff($submittedIds, $currentPhotos->keys()->all()) === [];

            if (! $isExactSet) {
                throw ValidationException::withMessages([
                    'photo_ids' => '排序清單必須包含此車輛目前所有照片，且不可重複或包含其他車輛的照片。',
                ]);
            }

            foreach ($submittedIds as $index => $photoId) {
                $currentPhotos[$photoId]->update(['sort_order' => $index]);
            }

            return $vehicle->photos()->get();
        });

        // 排序一次會影響這台車全部照片的 sort_order（最多 60 張），若比照上傳/刪除
        // 對每筆 VehiclePhoto 各記一筆 'updated'，一次排序動作就會在稽核紀錄裡灌入
        // 多達 60 筆幾乎沒有個別意義的雜訊。改成記一筆代表「這次排序動作」本身的
        // 紀錄，afterValues 完整記下最終順序，足以重建這次操作實際做了什麼。
        $this->auditLogService->recordVehiclePhotoReorder($vehicle, $submittedIds);

        return $result;
    }

    private function assertBelongsToVehicle(Vehicle $vehicle, VehiclePhoto $photo): void
    {
        if ($photo->vehicle_id !== $vehicle->id) {
            throw ValidationException::withMessages([
                'photo' => '此照片不屬於指定車輛。',
            ]);
        }
    }
}
