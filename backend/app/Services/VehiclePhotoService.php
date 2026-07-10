<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Models\VehiclePhotoUploadBatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class VehiclePhotoService
{
    public function __construct(
        private readonly VehiclePhotoImageProcessor $processor,
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
        // 檔案內容快照）。網路逾時、瀏覽器重複送出或 proxy 重試帶著同一把 key 重打這個
        // 端點時，內容相同就直接回傳當初建立的照片、不重複建立；內容不同則拒絕（Codex
        // adversarial review 指出先前完全沒有這層保護，重試會不斷疊加重複照片並吃掉
        // 每台車 60 張上限）。
        $payload = $this->buildUploadPayload($vehicle, $files);
        ['batch' => $batch, 'isNew' => $isNew] = $this->beginUploadBatch($vehicle, $idempotencyKey, $payload);

        if (! $isNew) {
            return VehiclePhoto::query()
                ->whereIn('id', $batch->photo_ids ?? [])
                ->orderBy('sort_order')
                ->get();
        }

        $created = [];

        // 圖片解碼/編碼（process()）刻意放在 DB transaction 之外執行：
        // VehiclePhotoImageProcessor 內部已用全域 lock 保護並發記憶體風險，若整段
        // 包進單一 DB transaction，會讓資料庫連線在漫長的圖片處理期間被佔用。每個
        // 檔案各自 process → create，任一步驟失敗都會清理「這個檔案自己」寫入的
        // storage 檔案，並回溯刪除本次請求中先前已成功建立的照片與檔案，確保不會
        // 留下沒有 DB row 的孤兒檔案（PLAN v1.2 第 2.5 節：檔案寫入失敗時不可留下
        // DB row，DB 寫入失敗時要有檔案清理策略）。
        //
        // 每張照片的「每台車照片上限 / 封面 / sort_order」計算與寫入都包在同一個
        // transaction 內，並先用 lockForUpdate() 鎖住該車輛的 row：若不鎖，兩個
        // 使用者同時對同一台車上傳照片時，各自讀到的既有照片數 / 封面狀態 /
        // 最大 sort_order 都可能是同一份舊快照，導致兩者都以為自己是第一張封面、
        // sort_order 互撞，或兩者各自檢查上限都通過但疊加起來超過
        // max_photos_per_vehicle（Codex adversarial review 指出）。用同一台車的
        // row lock 把「檢查上限 + 決定封面 + 決定 sort_order + 寫入」序列化，
        // 確保並發請求看到的一定是彼此的最新結果。
        try {
            foreach ($files as $file) {
                $data = $this->processor->process($file, $vehicle->id);

                try {
                    $photo = DB::transaction(function () use ($vehicle, $user, $data, $config) {
                        Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

                        $currentCount = VehiclePhoto::where('vehicle_id', $vehicle->id)->count();
                        if ($currentCount + 1 > $config['max_photos_per_vehicle']) {
                            throw ValidationException::withMessages([
                                'photos' => "每台車最多只能有 {$config['max_photos_per_vehicle']} 張照片。",
                            ]);
                        }

                        $hasCover = VehiclePhoto::where('vehicle_id', $vehicle->id)->where('is_cover', true)->exists();
                        $nextSortOrder = (int) (VehiclePhoto::where('vehicle_id', $vehicle->id)->max('sort_order') ?? -1) + 1;

                        return VehiclePhoto::create([
                            ...$data,
                            'vehicle_id' => $vehicle->id,
                            'sort_order' => $nextSortOrder,
                            'is_cover' => ! $hasCover,
                            'uploaded_by' => $user->id,
                        ]);
                    });
                } catch (\Throwable $e) {
                    $this->processor->delete($data['disk'], $data['path'], $data['thumbnail_path']);

                    throw $e;
                }

                $created[] = $photo;
            }
        } catch (\Throwable $e) {
            foreach ($created as $photo) {
                // 這些照片從未在任何 transaction 外被讀取過（整批上傳都還沒成功
                // 回應），沒有「已經被其他人看到、需要先隱藏」的顧慮，直接
                // finalizePhysicalDeletion() 清 storage + forceDelete()，避免留下
                // 用不到的 soft-delete tombstone row。
                $this->finalizePhysicalDeletion($photo);
            }

            // 這批上傳整體失敗，$batch 的 photo_ids 會永遠是 null。刪除這筆 reservation
            // row，讓使用者用同一把 idempotency_key 重新送出時，能被當成全新的第一次
            // 嘗試乾淨地重試，而不是永遠卡在「payload 相同、但 photo_ids 一直是 null」
            // 的半殘狀態、被 beginUploadBatch() 誤判成「仍在處理中」而拒絕重試。
            $batch->delete();

            throw $e;
        }

        // 所有照片都已經在各自的 transaction 內成功 commit——對使用者而言這次上傳已經
        // 真正成功了，接下來只是把 photo_ids 寫回 reservation row 供未來 replay 使用的
        // 收尾動作。如果這個 update() 本身失敗（例如當下 DB 連線短暫抖動），不能讓例外
        // 往外拋：那會讓呼叫端看到「上傳失敗」的假訊息，但照片其實已經建立成功，且
        // catch 區塊只會清理「這次呼叫自己建立」的照片，反而會產生「回報失敗、但照片
        // 其實留著」的不一致。更嚴重的是，若放著 $batch 不管，它會永遠卡在
        // payload 相符、但 photo_ids 仍是 null 的狀態，之後任何用同一把
        // idempotency_key 的重試都會被 beginUploadBatch() 誤判成「仍在處理中」而永遠
        // 卡死，沒有任何後續請求能修復——這比單純的重複建立照片更糟，因為它讓這把
        // key 完全無法再使用。因此這裡吞下例外，盡力刪除這筆 reservation row：刪除
        // 成功的話，之後用同一把 key 重試會被當成全新的第一次嘗試（在這個極罕見的
        // 雙重失敗窗口內，代價是可能重複建立這批照片，而不是永久卡死），仍然遠優於
        // 讓 key 永久失效。
        try {
            $batch->update(['photo_ids' => array_map(fn (VehiclePhoto $photo) => $photo->id, $created)]);
        } catch (\Throwable $e) {
            Log::warning('車輛照片批次記錄收尾失敗，嘗試清除 reservation row 避免 idempotency_key 永久卡死', [
                'vehicle_id' => $vehicle->id,
                'idempotency_key' => $batch->idempotency_key,
                'photo_ids' => array_map(fn (VehiclePhoto $photo) => $photo->id, $created),
                'exception' => $e->getMessage(),
            ]);

            try {
                $batch->delete();
            } catch (\Throwable $deleteException) {
                // 連刪除都失敗代表 DB 當下真的不可用，這種狀況下這把 key 仍可能卡死，
                // 但已經超出這次請求能自行修復的範圍，只能留下明確的 log 供人工排查。
                Log::warning('車輛照片批次 reservation row 清除失敗，idempotency_key 可能暫時無法重試', [
                    'vehicle_id' => $vehicle->id,
                    'idempotency_key' => $batch->idempotency_key,
                    'exception' => $deleteException->getMessage(),
                ]);
            }
        }

        return new Collection($created);
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
     * @return array{batch: VehiclePhotoUploadBatch, isNew: bool}
     */
    private function beginUploadBatch(Vehicle $vehicle, string $idempotencyKey, array $payload): array
    {
        try {
            $batch = DB::transaction(fn () => VehiclePhotoUploadBatch::create([
                'vehicle_id' => $vehicle->id,
                'idempotency_key' => $idempotencyKey,
                'idempotency_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]));

            return ['batch' => $batch, 'isNew' => true];
        } catch (QueryException $e) {
            if (! $this->isIdempotencyKeyUniqueViolation($e)) {
                throw $e;
            }

            // 沿用 VehicleService 既有的 race pattern：unique 違反後 rollback，開新
            // transaction 並 lockForUpdate 重新讀取真正贏得這把 key 的 row，避免在同一個
            // 已經失敗的 transaction 裡繼續讀取。
            return DB::transaction(function () use ($e, $idempotencyKey, $payload) {
                $existing = VehiclePhotoUploadBatch::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    throw $e;
                }

                $storedPayload = json_decode((string) $existing->idempotency_payload, true);
                if (! is_array($storedPayload) || $storedPayload !== $payload) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => '此 idempotency_key 已用於內容不同的照片上傳請求。',
                    ]);
                }

                if ($existing->photo_ids === null) {
                    // 極短暫的合法競態窗口：兩個帶著同一把 key 的請求幾乎同時抵達，
                    // 第一個已經贏得 reservation row 但檔案處理尚未完成。這裡不能假裝
                    // 已完成而回傳空結果，也不能卡住等待，直接請使用者稍後重試。
                    throw ValidationException::withMessages([
                        'idempotency_key' => '此照片上傳仍在處理中，請稍後再試一次。',
                    ]);
                }

                return ['batch' => $existing, 'isNew' => false];
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

    private function finalizePhysicalDeletion(VehiclePhoto $photo): void
    {
        $this->processor->delete($photo->disk, $photo->path, $photo->thumbnail_path);

        $photo->forceDelete();
    }

    public function setCover(Vehicle $vehicle, VehiclePhoto $photo): VehiclePhoto
    {
        $this->assertBelongsToVehicle($vehicle, $photo);

        return DB::transaction(function () use ($vehicle, $photo) {
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
            $freshPhoto->save();

            return $freshPhoto;
        });
    }

    /**
     * @param  array<int, int>  $photoIds
     */
    public function reorder(Vehicle $vehicle, array $photoIds): Collection
    {
        return DB::transaction(function () use ($vehicle, $photoIds) {
            Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

            // 鎖定車輛 row 之後才在 transaction 內重新讀取「此刻」這台車的完整照片
            // 清單，並要求提交的 photo_ids 剛好等於這個完整清單（不多不少、不重複），
            // 而不是只驗證「提交的 id 都屬於這台車」。只驗證子集合會讓只送一兩張
            // 照片 id 的請求把這幾筆 sort_order 重寫成從 0 開始，卻不動其餘照片原本
            // 的 sort_order，造成同車輛出現重複的 sort_order、透過 Vehicle::photos()
            // 排序結果變得不確定（Codex adversarial review 指出）。在鎖定後才讀取
            // 目前清單，也避免用 lock 之前、可能已過期的照片集合做驗證。
            $currentPhotos = $vehicle->photos()->get()->keyBy('id');
            $submittedIds = array_map('intval', $photoIds);

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
