<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Models\VehiclePhotoUploadBatch;
use App\Services\AuditLogService;
use App\Services\VehiclePhotoImageProcessor;
use App\Services\VehiclePhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 覆蓋 deletePhoto() 的 storage 清理失敗路徑：Codex adversarial review 指出，
 * 這種失敗不能讓 DELETE API 回報失敗（因為邏輯刪除已經真正生效），但也不能
 * 只在註解裡宣稱「之後可以安全重試」卻沒有實際會被執行到的重試機制。這裡驗證
 * 兩段都成立：deletePhoto() 對失敗保持沉默、purgeTrashedPhotos() 確實能把留下
 * 的 tombstone 清乾淨。
 */
class VehiclePhotoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeJpegUploadedFile(string $originalName = 'phone-photo.jpg', int $width = 400, int $height = 300): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 200));

        $path = tempnam(sys_get_temp_dir(), 'vehicle_photo_test_').'.jpg';
        imagejpeg($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $originalName, 'image/jpeg', null, true);
    }

    private function makePhoto(
        Vehicle $vehicle,
        User $user,
        string $path,
        string $thumbnailPath,
        ?int $uploadBatchId = null,
    ): VehiclePhoto {
        return VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'upload_batch_id' => $uploadBatchId,
            'disk' => 'public',
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'original_filename' => 'test.webp',
            'mime_type' => 'image/webp',
            'size' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 0,
            'is_cover' => true,
            'uploaded_by' => $user->id,
        ]);
    }

    public function test_delete_photo_swallows_storage_cleanup_failure_and_leaves_retryable_tombstone(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $photo = $this->makePhoto($vehicle, $user, 'vehicles/1/photo.webp', 'vehicles/1/photo_thumb.webp');

        $processor = Mockery::mock(VehiclePhotoImageProcessor::class);
        $processor->shouldReceive('delete')->once()->andThrow(new RuntimeException('storage 暫時無法連線'));
        $this->app->instance(VehiclePhotoImageProcessor::class, $processor);

        $service = app(VehiclePhotoService::class);

        // 不應該拋出例外：邏輯刪除（soft delete）已經在 DB transaction 內成功
        // commit，storage 清理失敗只留下 tombstone，不能讓呼叫端看到「刪除失敗」。
        $service->deletePhoto($vehicle, $photo);

        $this->assertSoftDeleted('vehicle_photos', ['id' => $photo->id]);
        $this->assertSame(1, VehiclePhoto::onlyTrashed()->where('id', $photo->id)->count());

        // 對外（一般查詢、route model binding）必須完全看不到這張照片。
        $this->assertSame(0, VehiclePhoto::query()->where('id', $photo->id)->count());
    }

    public function test_purge_trashed_photos_clears_tombstone_once_storage_is_reachable_again(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);

        $path = 'vehicles/'.$vehicle->id.'/photo.webp';
        $thumbnailPath = 'vehicles/'.$vehicle->id.'/photo_thumb.webp';
        Storage::disk('public')->put($path, 'fake-main-image-bytes');
        Storage::disk('public')->put($thumbnailPath, 'fake-thumbnail-bytes');

        $photo = $this->makePhoto($vehicle, $user, $path, $thumbnailPath);
        // 模擬前一次 deletePhoto() 已經 soft-delete 成功、但 storage 清理當時失敗
        // 留下的 tombstone；這裡直接呼叫 delete() 重現同一個狀態，不重複走一次
        // 上面已經驗證過的 deletePhoto() 邏輯。
        $photo->delete();

        $service = app(VehiclePhotoService::class);
        $result = $service->purgeTrashedPhotos();

        $this->assertSame(['purged' => 1, 'failed' => 0], $result);
        $this->assertDatabaseMissing('vehicle_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertMissing($thumbnailPath);
    }

    public function test_purge_trashed_photos_counts_failures_without_aborting_other_rows(): void
    {
        Storage::fake('public');

        $vehicleA = Vehicle::factory()->create();
        $vehicleB = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);

        $stuckPhoto = $this->makePhoto($vehicleA, $user, 'vehicles/'.$vehicleA->id.'/stuck.webp', 'vehicles/'.$vehicleA->id.'/stuck_thumb.webp');
        $stuckPhoto->delete();

        $recoverablePath = 'vehicles/'.$vehicleB->id.'/ok.webp';
        $recoverableThumbnailPath = 'vehicles/'.$vehicleB->id.'/ok_thumb.webp';
        Storage::disk('public')->put($recoverablePath, 'bytes');
        Storage::disk('public')->put($recoverableThumbnailPath, 'thumb-bytes');
        $recoverablePhoto = $this->makePhoto($vehicleB, $user, $recoverablePath, $recoverableThumbnailPath);
        $recoverablePhoto->delete();

        $processor = Mockery::mock(VehiclePhotoImageProcessor::class);
        $processor->shouldReceive('delete')
            ->with('public', $stuckPhoto->path, $stuckPhoto->thumbnail_path)
            ->once()
            ->andThrow(new RuntimeException('storage 仍然無法連線'));
        $processor->shouldReceive('delete')
            ->with('public', $recoverablePhoto->path, $recoverablePhoto->thumbnail_path)
            ->once();
        $this->app->instance(VehiclePhotoImageProcessor::class, $processor);

        $service = app(VehiclePhotoService::class);
        $result = $service->purgeTrashedPhotos();

        $this->assertSame(['purged' => 1, 'failed' => 1], $result);
        $this->assertSoftDeleted('vehicle_photos', ['id' => $stuckPhoto->id]);
    }

    /**
     * Codex adversarial review 指出照片上傳先前完全沒有 idempotency 保護：網路逾時、
     * 瀏覽器重複送出或 proxy 重試會直接建立重複照片，且使用者無法確定哪些是第一次
     * 嘗試留下的紀錄。這裡驗證同一把 idempotency_key + 同一組檔案內容重複呼叫時，
     * 不會建立第二批照片，而是直接回傳第一次建立的結果。
     */
    public function test_upload_photos_replays_same_result_for_repeated_idempotency_key_and_payload(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);
        $key = 'test-upload-key-'.uniqid();

        $first = $service->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile()], $key);
        $second = $service->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile()], $key);

        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        $this->assertSame(1, VehiclePhotoUploadBatch::where('idempotency_key', $key)->count());
    }

    /**
     * Codex stop-time review 指出：整批完成的判斷（batch.photo_ids 長度達到檔案
     * 總數）跟「曝光」（把這批照片的 upload_batch_id 清成 NULL）是兩個分開的寫入
     * 步驟。如果最後一個檔案的 photo_ids 已經 commit，但曝光那個 UPDATE 還沒執行
     * 或還沒 commit 前，處理程序就中斷（例如 worker 被強制中止），這批照片會卡在
     * 「已被視為完成、之後任何同一把 idempotency_key 的請求都直接回放」，但
     * upload_batch_id 從未被清空、永遠對外不可見的狀態——沒有任何後續機制會再
     * 重新嘗試曝光它們。這裡直接模擬這個中斷點：手刻一筆 photo_ids 長度已達檔案
     * 總數、但照片仍帶著 upload_batch_id 的 batch，驗證用同一把 key 重新呼叫
     * uploadPhotos()（進入 replay 分支）時會自我修復、把照片補曝光，而不是繼續
     * 讓它們永久隱形。
     */
    public function test_upload_photos_replay_self_heals_visibility_left_behind_by_a_crash_before_finalization(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);
        $key = 'test-upload-key-'.uniqid();
        $file = $this->fakeJpegUploadedFile();

        $payload = [
            'vehicle_id' => $vehicle->id,
            'files' => [[
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'size' => $file->getSize(),
                'original_filename' => $file->getClientOriginalName(),
            ]],
        ];

        $batch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => $key,
            'idempotency_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'photo_ids' => [],
            'processing_lease_expires_at' => now()->addMinutes(10),
        ]);

        // 模擬「最後一個檔案已經真的建立、photo_ids 已經寫回 batch，但曝光步驟
        // 還沒執行就中斷」：照片仍帶著 upload_batch_id，尚未提交完成。
        $stuckPhoto = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/stuck.webp',
            'vehicles/'.$vehicle->id.'/stuck_thumb.webp',
            $batch->id,
        );
        $batch->update(['photo_ids' => [$stuckPhoto->id], 'processing_lease_expires_at' => null]);

        // 中斷之後、修復之前：這張照片對外完全不可見。
        $this->assertSame(0, $vehicle->photos()->count());

        $result = $service->uploadPhotos($vehicle, $user, [$file], $key);

        $this->assertCount(1, $result);
        $this->assertSame($stuckPhoto->id, $result->first()->id);
        // 沒有重複建立新照片，只是把原本卡住的那一張補曝光。
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
        $this->assertSame(1, $vehicle->photos()->count());
        $stuckPhoto->refresh();
        $this->assertNull($stuckPhoto->upload_batch_id);
    }

    /**
     * Codex adversarial review 指出：若逐檔建立當下就依「目前是否已有
     * is_cover=true 的 row」決定要不要把自己設成封面，且這個查詢沒有排除其他
     * 批次還在中途、尚未提交的 hidden 照片，先佔走封面判斷的那個批次一旦後來
     * 失敗 rollback 或被判定放棄而 sweep 掉，之後才完成的批次卻早已因為誤判
     * 「已有封面」而放棄設封面，導致整台車最終沒有任何封面。修法是封面一律
     * 留到 finalizeBatchVisibility() 曝光那一刻才判斷，且只看「此刻已經正式
     * 可見」的照片。這裡驗證：即使有另一個批次的照片還卡在中途、對外不可見，
     * 新的一批上傳完成、正式曝光時仍然會正確拿到封面，且不會去動到那個隱藏、
     * 不相干的 row。
     */
    public function test_upload_photos_finalization_ignores_hidden_photo_from_another_in_progress_batch_when_assigning_cover(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);

        $staleBatch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => 'other-batch-key-'.uniqid(),
            'idempotency_payload' => json_encode(['vehicle_id' => $vehicle->id, 'files' => []], JSON_THROW_ON_ERROR),
            'photo_ids' => [],
            'processing_lease_expires_at' => null,
        ]);

        $hiddenPhoto = VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'upload_batch_id' => $staleBatch->id,
            'disk' => 'public',
            'path' => 'vehicles/'.$vehicle->id.'/hidden.webp',
            'thumbnail_path' => 'vehicles/'.$vehicle->id.'/hidden_thumb.webp',
            'original_filename' => 'test.webp',
            'mime_type' => 'image/webp',
            'size' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 0,
            // 對照新的曝光時機規則：這個模擬另一個批次中途建立的照片本身就
            // 不該帶著 is_cover=true（見 uploadPhotos() 建立照片時的說明），
            // 這裡刻意用 false 反映曝光前唯一合法的狀態。
            'is_cover' => false,
            'uploaded_by' => $user->id,
        ]);

        // 這批照片還在另一個批次中途、尚未提交，對外完全不可見。
        $this->assertSame(0, $vehicle->photos()->count());

        $service = app(VehiclePhotoService::class);
        $result = $service->uploadPhotos(
            $vehicle,
            $user,
            [$this->fakeJpegUploadedFile('a.jpg')],
            'test-upload-key-'.uniqid(),
        );

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is_cover);
        $this->assertSame(1, $vehicle->photos()->count());

        // 隱藏批次的殘留 row 完全沒被動到，仍然不可見、仍然不是封面。
        $hiddenPhoto->refresh();
        $this->assertNotNull($hiddenPhoto->upload_batch_id);
        $this->assertFalse($hiddenPhoto->is_cover);
    }

    /**
     * cover_slot 是不分可見／隱藏的 DB-wide unique 索引：只要這台車有任何一筆
     * row 帶著 is_cover=true，不論它此刻是否可見，都會佔用這台車唯一的
     * cover_slot 值。這次修正之後，正常的上傳流程不會再讓任何隱藏中的照片帶著
     * is_cover=true，但這裡直接模擬「這次修正上線前就已經卡住的殘留資料」——
     * 一筆隱藏、對外不可見，卻已經帶著 is_cover=true 的舊 row。若曝光/指定封面
     * 時沒有先清掉這種殘留資料，直接對新照片寫 is_cover=true 會撞到 unique
     * constraint，讓整個曝光 transaction 失敗（Codex stop-time review 指出）。
     */
    public function test_upload_photos_finalization_self_heals_legacy_hidden_cover_row_instead_of_crashing(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);

        $staleBatch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => 'other-batch-key-'.uniqid(),
            'idempotency_payload' => json_encode(['vehicle_id' => $vehicle->id, 'files' => []], JSON_THROW_ON_ERROR),
            'photo_ids' => [],
            'processing_lease_expires_at' => null,
        ]);

        $legacyHiddenCoverPhoto = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/legacy-hidden-cover.webp',
            'vehicles/'.$vehicle->id.'/legacy-hidden-cover_thumb.webp',
            $staleBatch->id,
        );
        $this->assertTrue($legacyHiddenCoverPhoto->is_cover);

        // 這筆殘留資料還在另一個批次中途、對外完全不可見。
        $this->assertSame(0, $vehicle->photos()->count());

        $service = app(VehiclePhotoService::class);
        $result = $service->uploadPhotos(
            $vehicle,
            $user,
            [$this->fakeJpegUploadedFile('a.jpg')],
            'test-upload-key-'.uniqid(),
        );

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is_cover);
        $this->assertSame(1, $vehicle->photos()->count());

        // 殘留資料被順手修復成 is_cover=false，讓新曝光的照片能安全成為唯一封面，
        // 不再需要開發者手動介入資料庫清理。
        $legacyHiddenCoverPhoto->refresh();
        $this->assertNotNull($legacyHiddenCoverPhoto->upload_batch_id);
        $this->assertFalse($legacyHiddenCoverPhoto->is_cover);
    }

    /**
     * cover_slot 這個 unique 索引是直接算在實體 row 上，完全不理解 Laravel 的
     * soft-delete scope：一筆已經被邏輯刪除（deleted_at 不為 NULL）、卻還帶著
     * is_cover=true 的殘留 row，物理上仍然佔用著這台車唯一的 cover_slot 值。
     * 清除殘留封面時若沿用 Eloquent 預設的查詢（會自動排除 soft-deleted row），
     * 就完全看不到、也清不掉這種殘留資料，一樣會讓新封面的寫入撞到 unique
     * constraint 而失敗（Codex stop-time review 指出）。這裡直接模擬這種已被
     * 邏輯刪除的殘留封面 row，驗證清除時確實有用 withTrashed() 涵蓋到它。
     */
    public function test_upload_photos_finalization_self_heals_soft_deleted_legacy_cover_row_instead_of_crashing(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);

        $staleBatch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => 'other-batch-key-'.uniqid(),
            'idempotency_payload' => json_encode(['vehicle_id' => $vehicle->id, 'files' => []], JSON_THROW_ON_ERROR),
            'photo_ids' => [],
            'processing_lease_expires_at' => null,
        ]);

        $softDeletedLegacyCoverPhoto = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/soft-deleted-legacy-cover.webp',
            'vehicles/'.$vehicle->id.'/soft-deleted-legacy-cover_thumb.webp',
            $staleBatch->id,
        );
        $softDeletedLegacyCoverPhoto->delete();
        $this->assertTrue($softDeletedLegacyCoverPhoto->is_cover);
        $this->assertTrue($softDeletedLegacyCoverPhoto->trashed());

        $this->assertSame(0, $vehicle->photos()->count());

        $service = app(VehiclePhotoService::class);
        $result = $service->uploadPhotos(
            $vehicle,
            $user,
            [$this->fakeJpegUploadedFile('a.jpg')],
            'test-upload-key-'.uniqid(),
        );

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is_cover);
        $this->assertSame(1, $vehicle->photos()->count());

        $softDeletedLegacyCoverPhoto->refresh();
        $this->assertTrue($softDeletedLegacyCoverPhoto->trashed());
        $this->assertFalse($softDeletedLegacyCoverPhoto->is_cover);
    }

    /**
     * 同一把 idempotency_key 若被用在內容不同的第二次請求（例如不同檔案），
     * 必須拒絕，不能誤判成單純的重試而回放第一次的結果。
     */
    public function test_upload_photos_rejects_different_payload_reusing_idempotency_key(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);
        $key = 'test-upload-key-'.uniqid();

        $service->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile('a.jpg')], $key);

        $this->expectException(ValidationException::class);
        $service->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile('b.jpg', 500, 500)], $key);
    }

    /**
     * 若這批上傳整體失敗（例如某個檔案處理中途出錯），batch row 必須保留（供後續
     * 續傳比對 payload），但 photo_ids 要復原回失敗前的進度、租約要立刻清空，讓
     * 使用者用同一把 idempotency_key 重新送出時能立刻重試，不需要等到 TTL，也不會
     * 被 beginUploadBatch() 誤判成「上一次仍在處理中」而卡住。
     */
    public function test_upload_photos_resets_progress_after_failure_so_same_key_can_retry_immediately(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $key = 'test-upload-key-'.uniqid();

        $processor = Mockery::mock(VehiclePhotoImageProcessor::class);
        $processor->shouldReceive('process')->once()->andThrow(new RuntimeException('模擬處理失敗'));
        $this->app->instance(VehiclePhotoImageProcessor::class, $processor);
        $service = app(VehiclePhotoService::class);

        try {
            $service->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile()], $key);
            $this->fail('預期應拋出例外');
        } catch (RuntimeException) {
            // 預期中的失敗，繼續驗證清理結果。
        }

        $this->assertSame(1, VehiclePhotoUploadBatch::where('idempotency_key', $key)->count());
        $batch = VehiclePhotoUploadBatch::where('idempotency_key', $key)->first();
        $this->assertSame([], $batch->photo_ids);
        $this->assertNull($batch->processing_lease_expires_at);

        $this->app->forgetInstance(VehiclePhotoImageProcessor::class);
        $retryService = app(VehiclePhotoService::class);
        $result = $retryService->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile()], $key);

        $this->assertCount(1, $result);
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    /**
     * Codex adversarial review（第四輪）指出：先前版本的租約回收機制只在「認領」那
     * 一刻核發新的 processing_lease_expires_at，卻沒有在認領之後的每一次寫入都驗證
     * 擁有權。若前一個擁有者其實只是跑得比租約久、尚未真正放棄，它手上握著的舊
     * $batch 物件仍可能在被續傳認領之後繼續寫入，覆蓋掉新擁有者已經寫入、甚至已經
     * 回傳給使用者的進度。這裡直接測試 fencing 機制本身：模擬一筆已經被新請求續傳
     * 認領（核發新 claim_token）的 batch，驗證前一個擁有者拿著舊 token 嘗試寫入
     * 一定會被擋下（回傳 false、完全不寫入任何欄位），新擁有者的進度完全不受影響。
     */
    public function test_stale_claim_token_writes_are_rejected_after_batch_is_superseded(): void
    {
        Storage::fake('public');
        config(['vehicle_photos.upload_batch_pending_ttl_seconds' => 60]);

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);
        $key = 'test-upload-key-'.uniqid();
        $file = $this->fakeJpegUploadedFile();

        $staleToken = 'stale-token-'.uniqid();
        $batch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => $key,
            'idempotency_payload' => json_encode([
                'vehicle_id' => $vehicle->id,
                'files' => [[
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'size' => $file->getSize(),
                    'original_filename' => $file->getClientOriginalName(),
                ]],
            ], JSON_THROW_ON_ERROR),
            'photo_ids' => [],
            // 模擬前一個擁有者（「request A」）的租約已過期，永遠沒有把它跑完。
            'processing_lease_expires_at' => now()->subSeconds(10),
            'claim_token' => $staleToken,
        ]);

        // 「request B」：租約過期後續傳認領，核發新 token 並完整處理完成。
        $result = $service->uploadPhotos($vehicle, $user, [$file], $key);
        $this->assertCount(1, $result);

        $batch->refresh();
        $this->assertNotSame($staleToken, $batch->claim_token);
        $this->assertSame([$result->first()->id], $batch->photo_ids);

        // 「request A」：模擬原本那個已經被取代的請求，手上還握著舊的 $staleToken，
        // 嘗試繼續寫入。直接呼叫 applyBatchUpdateIfOwned()——這是 uploadPhotos()
        // 內部每一次寫入實際使用的同一個方法，直接驗證 fencing 檢查本身。
        $reflection = new \ReflectionMethod(VehiclePhotoService::class, 'applyBatchUpdateIfOwned');
        $reflection->setAccessible(true);
        $staleOwnerView = VehiclePhotoUploadBatch::findOrFail($batch->id);
        $owned = $reflection->invoke($service, $staleOwnerView, $staleToken, ['photo_ids' => []]);

        $this->assertFalse($owned);

        // B 已經完成的進度完全沒有被 A 的過期寫入影響。
        $batch->refresh();
        $this->assertSame([$result->first()->id], $batch->photo_ids);
    }

    /**
     * 對照上一個測試：這裡直接透過 uploadPhotos() 的正常呼叫路徑（不繞過反射）觸發
     * 同樣的情境——處理到一半時，這個請求所擁有的 batch 被另一個請求續傳認領。驗證
     * 已經在被取代之前、以合法 claim_token 寫入的第一張照片會被保留，第二個檔案因為
     * fencing 檢查失敗完全不會留下照片，且呼叫端會收到明確的錯誤，而不是靜默的假
     * 成功或誤刪別人的進度。
     */
    public function test_upload_photos_rolls_back_only_the_superseded_files_when_taken_over_mid_loop(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $key = 'test-upload-key-'.uniqid();

        $realProcessor = app(VehiclePhotoImageProcessor::class);
        $callCount = 0;
        $processor = Mockery::mock(VehiclePhotoImageProcessor::class);
        $processor->shouldReceive('process')
            ->twice()
            ->andReturnUsing(function ($file, $vehicleId) use ($realProcessor, &$callCount, $key) {
                $callCount++;
                if ($callCount === 2) {
                    // 模擬另一個請求在第一個檔案處理完成、第二個檔案開始處理前，
                    // 續傳認領了同一筆 batch（核發新的 claim_token）。
                    VehiclePhotoUploadBatch::where('idempotency_key', $key)
                        ->update(['claim_token' => 'intruder-token']);
                }

                return $realProcessor->process($file, $vehicleId);
            });
        $processor->shouldReceive('delete')->andReturnUsing(fn (...$args) => $realProcessor->delete(...$args));
        $this->app->instance(VehiclePhotoImageProcessor::class, $processor);

        $service = app(VehiclePhotoService::class);

        try {
            $service->uploadPhotos(
                $vehicle,
                $user,
                [$this->fakeJpegUploadedFile('a.jpg'), $this->fakeJpegUploadedFile('b.jpg', 500, 500)],
                $key,
            );
            $this->fail('預期應拋出例外');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }

        // 第一個檔案是在被取代之前、以合法 claim_token 寫入，必須保留，不能被這次
        // 失敗的請求牽連刪除；第二個檔案因為 fencing 檢查失敗，完全沒有留下照片。
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());

        $batch = VehiclePhotoUploadBatch::where('idempotency_key', $key)->first();
        $this->assertSame('intruder-token', $batch->claim_token);
        $this->assertCount(1, $batch->photo_ids);
    }

    /**
     * Codex stop-time review（第五輪）指出：上一版的修正只在「透過 fencing 檢查偵測
     * 到已被取代」時才不刪除既有照片；如果第二個檔案是因為完全無關的原因失敗
     * （例如圖片本身損毀，在還沒進到 DB transaction、根本沒機會做 fencing 檢查前就
     * 已經拋出），一般失敗的清理路徑仍會無條件刪除這次呼叫自己建立的所有照片——
     * 即使其中第一張照片是在被取代之前、以合法 claim_token 成功寫入
     * batch.photo_ids，新的擁有者可能已經完成並回傳給使用者。這裡直接驗證這種
     * 「清理善後時才發現已被取代」的情境，第一張照片必須被保留。
     */
    public function test_upload_photos_preserves_accepted_photos_when_supersession_is_discovered_during_generic_failure_cleanup(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $key = 'test-upload-key-'.uniqid();

        $realProcessor = app(VehiclePhotoImageProcessor::class);
        $callCount = 0;
        $processor = Mockery::mock(VehiclePhotoImageProcessor::class);
        $processor->shouldReceive('process')
            ->twice()
            ->andReturnUsing(function ($file, $vehicleId) use ($realProcessor, &$callCount, $key) {
                $callCount++;
                if ($callCount === 2) {
                    // 模擬另一個請求在第一個檔案處理完成、第二個檔案開始處理前，
                    // 續傳認領了同一筆 batch；接著這個檔案本身處理失敗，且失敗原因
                    // 與被取代完全無關（例如圖片損毀），在還沒機會做任何 fencing
                    // 檢查前就直接拋出。
                    VehiclePhotoUploadBatch::where('idempotency_key', $key)
                        ->update(['claim_token' => 'intruder-token-generic-failure']);

                    throw ValidationException::withMessages([
                        'photos' => '模擬圖片損毀，與被取代無關的獨立處理失敗',
                    ]);
                }

                return $realProcessor->process($file, $vehicleId);
            });
        $processor->shouldReceive('delete')->andReturnUsing(fn (...$args) => $realProcessor->delete(...$args));
        $this->app->instance(VehiclePhotoImageProcessor::class, $processor);

        $service = app(VehiclePhotoService::class);

        try {
            $service->uploadPhotos(
                $vehicle,
                $user,
                [$this->fakeJpegUploadedFile('a.jpg'), $this->fakeJpegUploadedFile('b.jpg', 500, 500)],
                $key,
            );
            $this->fail('預期應拋出例外');
        } catch (ValidationException $e) {
            // 應該收到「已被取代」的錯誤，而不是「圖片損毀」的原始錯誤——一旦發現
            // 自己已經不再擁有這個 batch，原始失敗原因對使用者而言已經是過期資訊。
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }

        // 第一個檔案是在被取代之前、以合法 claim_token 成功寫入的照片，即使第二個
        // 檔案因為完全無關的原因失敗、觸發整批清理，也絕對不能被牽連刪除。
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());

        $batch = VehiclePhotoUploadBatch::where('idempotency_key', $key)->first();
        $this->assertSame('intruder-token-generic-failure', $batch->claim_token);
        $this->assertCount(1, $batch->photo_ids);
    }

    /**
     * Codex adversarial review（第六輪）指出：先前版本的失敗回滾分成兩個獨立步驟——
     * 先復原 batch.photo_ids／清空租約，再刪除照片本體。一旦「復原 batch」先
     * commit，另一個帶著同一把 idempotency_key 的請求就可能立刻認領、重新處理
     * 這幾個檔案，此時舊照片可能還「可見」，造成短暫但真實的重複可見視窗；若
     * storage 清理又失敗，這些孤兒照片甚至會永久留著。這裡驗證修正後的行為：
     * 回滾的照片會在跟 batch 復原「同一個 transaction」內立刻 soft-delete，即使
     * storage 實體清理隨後失敗，也不會影響「這批照片已經對外隱藏」這件事，同一把
     * idempotency_key 可以立刻安全重試，不會撞到還可見的舊照片、也不會遺失
     * 已經真正建立的第一張照片的清理紀錄（tombstone）。
     */
    public function test_upload_photos_rolled_back_photos_are_soft_deleted_even_when_storage_cleanup_fails_and_retry_is_safe(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $key = 'test-upload-key-'.uniqid();

        $realProcessor = app(VehiclePhotoImageProcessor::class);
        $callCount = 0;
        $processor = Mockery::mock(VehiclePhotoImageProcessor::class);
        $processor->shouldReceive('process')
            ->twice()
            ->andReturnUsing(function ($file, $vehicleId) use ($realProcessor, &$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    throw ValidationException::withMessages([
                        'photos' => '模擬第二個檔案處理失敗',
                    ]);
                }

                return $realProcessor->process($file, $vehicleId);
            });
        // 第一個檔案的照片被回滾時，storage 實體清理刻意失敗，模擬 storage 暫時
        // 不可用，驗證即使如此，soft-delete（邏輯刪除）仍然先落地生效。
        $processor->shouldReceive('delete')->once()->andThrow(new RuntimeException('storage 暫時無法連線'));
        $this->app->instance(VehiclePhotoImageProcessor::class, $processor);

        $service = app(VehiclePhotoService::class);

        try {
            $service->uploadPhotos(
                $vehicle,
                $user,
                [$this->fakeJpegUploadedFile('a.jpg'), $this->fakeJpegUploadedFile('b.jpg', 500, 500)],
                $key,
            );
            $this->fail('預期應拋出例外');
        } catch (ValidationException $e) {
            // 即使收尾的 storage 清理失敗，回傳給呼叫端的仍然是這次真正發生的原始
            // 錯誤（第二個檔案處理失敗），不會被清理過程中的例外蓋掉。
            $this->assertArrayHasKey('photos', $e->errors());
        }

        // 第一個檔案的照片雖然 storage 清理失敗、實體檔案還留著，但已經被
        // soft-delete，一般查詢完全看不到，不會被算進車輛照片數量。
        $this->assertSame(0, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
        $this->assertSame(1, VehiclePhoto::onlyTrashed()->where('vehicle_id', $vehicle->id)->count());

        // 同一把 idempotency_key 立刻重試，不需要等 storage 清理完成，也不會撞到
        // 前一次留下的 tombstone 或造成重複。$service 這個實例的建構子已經綁定
        // 舊的 mock processor（delete() 的 once() 期望已經被上面用掉），這裡改用
        // 一個新解析出來、綁定真正 processor 的 service 實例。
        $this->app->forgetInstance(VehiclePhotoImageProcessor::class);
        $retryService = app(VehiclePhotoService::class);
        $retryResult = $retryService->uploadPhotos(
            $vehicle,
            $user,
            [$this->fakeJpegUploadedFile('a.jpg'), $this->fakeJpegUploadedFile('b.jpg', 500, 500)],
            $key,
        );

        $this->assertCount(2, $retryResult);
        $this->assertSame(2, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());

        // 前一次留下的 tombstone 仍然可以被排程 purgeTrashedPhotos() 正常清理，
        // 不會因為 batch 已經復原/重試過就變成孤兒、永遠沒人處理。
        $purgeResult = $retryService->purgeTrashedPhotos();
        $this->assertSame(['purged' => 1, 'failed' => 0], $purgeResult);
    }

    /**
     * Codex adversarial review（第七輪）指出：restoreBatchAndSoftDeleteRolledBackPhotos()
     * 本身是一個 DB transaction，若這個 transaction 自己失敗（例如 deadlock 重試 3
     * 次仍失敗），先前版本完全沒有處理：底層原始的 SQL 例外會直接外洩，蓋掉真正
     * 的失敗原因，呼叫端拿到的是一個不清楚、不可預期的錯誤。這裡驗證修正後的行為：
     * 用匿名子類別覆寫這個 protected 方法模擬「復原 transaction 本身也失敗」，
     * 確認 uploadPhotos() 會回傳一個清楚、誠實的錯誤（不是原始的圖片處理失敗訊息，
     * 也不是底層例外），且因為整個復原 transaction 從未 commit，第一個檔案的照片
     * 與 batch 進度都完整維持在「失敗前最後一次成功寫入」的狀態——不是不上不下的
     * 半殘狀態，只是沒有立即復原成可重試，會退回沿用既有的租約 TTL 機制自然恢復。
     */
    public function test_upload_photos_reports_clear_error_and_preserves_state_when_rollback_transaction_itself_fails(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $key = 'test-upload-key-'.uniqid();

        $realProcessor = app(VehiclePhotoImageProcessor::class);
        $callCount = 0;
        $processor = Mockery::mock(VehiclePhotoImageProcessor::class);
        $processor->shouldReceive('process')
            ->twice()
            ->andReturnUsing(function ($file, $vehicleId) use ($realProcessor, &$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    throw ValidationException::withMessages([
                        'photos' => '模擬第二個檔案處理失敗',
                    ]);
                }

                return $realProcessor->process($file, $vehicleId);
            });
        $this->app->instance(VehiclePhotoImageProcessor::class, $processor);

        $service = new class(app(VehiclePhotoImageProcessor::class), app(AuditLogService::class)) extends VehiclePhotoService
        {
            protected function restoreBatchAndSoftDeleteRolledBackPhotos(
                Vehicle $vehicle,
                VehiclePhotoUploadBatch $batch,
                string $claimToken,
                array $alreadyDoneIds,
                array $newlyCreatedThisCall,
            ): bool {
                throw new RuntimeException('模擬復原 transaction 本身也失敗（例如 deadlock 重試 3 次仍失敗）');
            }
        };

        try {
            $service->uploadPhotos(
                $vehicle,
                $user,
                [$this->fakeJpegUploadedFile('a.jpg'), $this->fakeJpegUploadedFile('b.jpg', 500, 500)],
                $key,
            );
            $this->fail('預期應拋出例外');
        } catch (ValidationException $e) {
            // 應該收到清楚、誠實的錯誤，不是原始的「圖片損毀」訊息，也不是底層
            // RuntimeException 直接外洩。
            $this->assertArrayHasKey('photos', $e->errors());
        }

        // 復原 transaction 從未 commit：第一個檔案的照片完全沒被動過，仍然可見
        // （不是 soft-delete，也沒被實體刪除），batch.photo_ids 仍指向它，租約
        // 也還是失敗前最後一次核發的值，尚未被清空——這是「還沒來得及復原」的
        // 有效狀態，不是資料損毀，會在租約自然過期後被下一次請求安全續傳。
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
        $this->assertSame(0, VehiclePhoto::onlyTrashed()->where('vehicle_id', $vehicle->id)->count());

        $batch = VehiclePhotoUploadBatch::where('idempotency_key', $key)->first();
        $this->assertCount(1, $batch->photo_ids);
        $this->assertNotNull($batch->processing_lease_expires_at);
    }

    /**
     * Codex adversarial review（第二輪）指出：先前的修正只處理了「服務自己的 catch
     * 區塊有機會執行到」的失敗。若 worker 被強制中止、遇到 fatal error，或伺服器
     * 重啟，PHP 根本沒有機會執行到任何 catch 區塊，batch 的 processing_lease_expires_at
     * 會停在過去某次設定的值，需要有機制讓之後帶著同一把 idempotency_key 的請求安全
     * 續傳，否則這把 key 會永久卡死、需要人工介入資料庫才能恢復。這裡直接模擬這種
     * 「處理程序消失、row 留在 DB 裡」的狀態（不透過 uploadPhotos() 產生，因為那
     * 必然會經過我們自己的 catch 區塊），驗證租約過期後，同一把 key 可以被自動續傳
     * 認領、正常完成上傳，不需要人工清資料庫。
     */
    public function test_upload_photos_resumes_stale_pending_batch_after_lease_expires(): void
    {
        Storage::fake('public');
        config(['vehicle_photos.upload_batch_pending_ttl_seconds' => 60]);

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);
        $key = 'test-upload-key-'.uniqid();
        $file = $this->fakeJpegUploadedFile();

        $payload = [
            'vehicle_id' => $vehicle->id,
            'files' => [[
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'size' => $file->getSize(),
                'original_filename' => $file->getClientOriginalName(),
            ]],
        ];

        $staleBatch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => $key,
            'idempotency_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'photo_ids' => [],
            // 模擬前一次處理程序被中止、租約已經過期，永遠沒有人把它跑完。
            'processing_lease_expires_at' => now()->subSeconds(10),
        ]);

        $result = $service->uploadPhotos($vehicle, $user, [$file], $key);

        $this->assertCount(1, $result);
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
        // 續傳認領同一筆 row（更新租約），不是刪除重建成新的一筆。
        $this->assertSame(1, VehiclePhotoUploadBatch::where('idempotency_key', $key)->count());
        $this->assertDatabaseHas('vehicle_photo_upload_batches', ['id' => $staleBatch->id]);
    }

    /**
     * Codex adversarial review（第三輪）指出：先前版本「租約/TTL 過期就整批放棄並
     * 重新處理」的回收設計，對「前一次處理程序已經成功建立一部分照片、只是還沒
     * 處理完全部檔案就被中止」這種部分完成的批次不安全——回收後會把整批檔案（包含
     * 已經真的建立過照片的那幾個）全部重新處理一次，等於系統性地重複建立照片。
     * 這裡直接驗證修正後的續傳行為：租約過期的部分完成批次，只會接著處理「還沒做
     * 的那幾個檔案」，已經完成的第一個檔案不會被重複建立。
     */
    public function test_upload_photos_resumes_only_remaining_files_without_duplicating_completed_ones(): void
    {
        Storage::fake('public');
        config(['vehicle_photos.upload_batch_pending_ttl_seconds' => 60]);

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);
        $key = 'test-upload-key-'.uniqid();

        $fileA = $this->fakeJpegUploadedFile('a.jpg');
        $fileB = $this->fakeJpegUploadedFile('b.jpg', 500, 500);

        // 手刻一筆「payload 涵蓋 fileA + fileB，但 photo_ids 只記錄 fileA 已完成、
        // 租約已過期」的 batch row，模擬前一次處理程序成功處理完第一個檔案後才被
        // 中止的狀態。
        $existingPhoto = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/existing.webp',
            'vehicles/'.$vehicle->id.'/existing_thumb.webp',
        );

        $payload = [
            'vehicle_id' => $vehicle->id,
            'files' => [
                [
                    'sha256' => hash_file('sha256', $fileA->getRealPath()),
                    'size' => $fileA->getSize(),
                    'original_filename' => $fileA->getClientOriginalName(),
                ],
                [
                    'sha256' => hash_file('sha256', $fileB->getRealPath()),
                    'size' => $fileB->getSize(),
                    'original_filename' => $fileB->getClientOriginalName(),
                ],
            ],
        ];

        VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => $key,
            'idempotency_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'photo_ids' => [$existingPhoto->id],
            'processing_lease_expires_at' => now()->subSeconds(10),
        ]);

        $result = $service->uploadPhotos($vehicle, $user, [$fileA, $fileB], $key);

        // 總共只有 2 張照片：先前已完成的 fileA + 這次續傳新建立的 fileB，
        // fileA 不會被重複建立。
        $this->assertCount(2, $result);
        $this->assertSame($existingPhoto->id, $result->first()->id);
        $this->assertSame(2, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());

        // $existingPhoto 代表「前一次處理程序建立完照片後、程序才被中止」的情境：
        // 它從未走到前一次呼叫的稽核記錄邏輯，DB 裡完全沒有它的 created 稽核紀錄
        // （見上面手刻的 batch row，只插入了照片本身，沒有任何 AuditLog）。這次續傳
        // 呼叫必須把這筆遺漏的紀錄補上，不能只記這次呼叫真正新建立的 fileB（Codex
        // stop-time review 指出：partial upload resume 可能讓照片曝光卻沒有 created
        // 稽核紀錄）。
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_CREATED,
            'subject_type' => 'vehicle_photo',
            'subject_id' => $existingPhoto->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_CREATED,
            'subject_type' => 'vehicle_photo',
            'subject_id' => $result->last()->id,
        ]);
        $this->assertSame(
            2,
            AuditLog::query()->where('subject_type', 'vehicle_photo')->where('action', AuditLog::ACTION_CREATED)->count(),
        );
    }

    /**
     * Codex stop-time review 指出的另一半情境：一批照片全部處理完成、也已曝光，
     * 但建立它們的那次呼叫在跑到稽核記錄之前就被中止，之後每一次帶著同一把
     * idempotency_key 的請求都只會走 'replay' 分支直接回放既有 row，若 replay
     * 分支完全不管稽核紀錄，這些照片就永久不會有 created 紀錄。
     */
    public function test_upload_photos_replay_backfills_missing_audit_log_for_already_completed_batch(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);
        $key = 'test-upload-key-'.uniqid();
        $file = $this->fakeJpegUploadedFile();

        $completedPhoto = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/completed.webp',
            'vehicles/'.$vehicle->id.'/completed_thumb.webp',
        );

        VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => $key,
            'idempotency_payload' => json_encode([
                'vehicle_id' => $vehicle->id,
                'files' => [[
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'size' => $file->getSize(),
                    'original_filename' => $file->getClientOriginalName(),
                ]],
            ], JSON_THROW_ON_ERROR),
            // photo_ids 長度已達檔案總數（1），會被 beginUploadBatch() 判定為
            // 'replay' 模式；完全沒有任何 audit_logs row，模擬建立它的那次呼叫
            // 從未跑到稽核記錄就被中止。
            'photo_ids' => [$completedPhoto->id],
            'processing_lease_expires_at' => null,
        ]);

        $this->assertSame(0, AuditLog::query()->where('subject_type', 'vehicle_photo')->count());

        $result = $service->uploadPhotos($vehicle, $user, [$file], $key);

        $this->assertCount(1, $result);
        $this->assertSame($completedPhoto->id, $result->first()->id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACTION_CREATED,
            'subject_type' => 'vehicle_photo',
            'subject_id' => $completedPhoto->id,
        ]);

        // 再 replay 一次，確認不會補記出第二筆重複紀錄。
        $service->uploadPhotos($vehicle, $user, [$file], $key);
        $this->assertSame(
            1,
            AuditLog::query()->where('subject_type', 'vehicle_photo')->where('action', AuditLog::ACTION_CREATED)->count(),
        );
    }

    /**
     * 對照上一個測試：租約仍在未來的「真的還在處理中」狀態不能被誤判成放棄並續傳，
     * 否則會跟真的還在跑的並發請求打架，同一批檔案被建立兩次。
     */
    public function test_upload_photos_rejects_while_processing_lease_still_active(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);
        $key = 'test-upload-key-'.uniqid();
        $file = $this->fakeJpegUploadedFile();

        $payload = [
            'vehicle_id' => $vehicle->id,
            'files' => [[
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'size' => $file->getSize(),
                'original_filename' => $file->getClientOriginalName(),
            ]],
        ];

        VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => $key,
            'idempotency_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'photo_ids' => [],
            'processing_lease_expires_at' => now()->addMinutes(10),
        ]);

        $this->expectException(ValidationException::class);
        $service->uploadPhotos($vehicle, $user, [$file], $key);
    }

    /**
     * Codex adversarial review（第八輪，之後的 adversarial review 再指出一次）
     * 指出：upload_batch_pending_ttl_seconds 過期只代表「允許下一個真實請求續傳
     * 認領」，不保證真的會有請求回來——如果使用者放棄重試，這批上傳裡已經真的
     * 建立好的照片不能一直以正常、可見的 VehiclePhoto row 留著，讓一次從未真正
     * 完成的上傳看起來像是成功上傳了一部分，之後又被排程無預警清掉、造成使用者
     * 已經看過的資料憑空消失。這裡的 $leftoverPhoto 刻意帶著
     * `upload_batch_id => $batch->id`，模擬 uploadPhotos() 逐檔建立當下、整批
     * 尚未完成、還沒被最終提交的真實狀態：先驗證這種照片從一開始就不會出現在
     * $vehicle->photos()（一般列表/封面/排序/public API 共用的同一個關聯）裡，
     * 再驗證 abandonStaleIncompleteUploadBatches() 把它清理掉時，不會有任何
     * 使用者可觀察到的資料消失——因為它從頭到尾都不曾對外可見過。
     */
    public function test_abandon_stale_incomplete_upload_batches_soft_deletes_leftover_photos_and_removes_batch(): void
    {
        Storage::fake('public');
        config(['vehicle_photos.upload_batch_abandon_sweep_seconds' => 60]);

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);

        $batch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => 'test-upload-key-'.uniqid(),
            'idempotency_payload' => json_encode([
                'vehicle_id' => $vehicle->id,
                'files' => [
                    ['sha256' => 'a', 'size' => 1, 'original_filename' => 'a.jpg'],
                    ['sha256' => 'b', 'size' => 1, 'original_filename' => 'b.jpg'],
                ],
            ], JSON_THROW_ON_ERROR),
            'photo_ids' => [],
            // 遠超過 60 秒的永久放棄門檻，且早已超過任何合理的續傳 TTL。
            'processing_lease_expires_at' => now()->subDay(),
        ]);

        $leftoverPhoto = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/leftover.webp',
            'vehicles/'.$vehicle->id.'/leftover_thumb.webp',
            $batch->id,
        );
        $batch->update(['photo_ids' => [$leftoverPhoto->id]]);
        // create() 一律把 updated_at 填成「現在」，這裡另外 backdate 成遠超過永久
        // 放棄門檻，模擬「這筆批次真的已經很久沒有任何人動過」。
        DB::table('vehicle_photo_upload_batches')->where('id', $batch->id)->update(['updated_at' => now()->subDay()]);

        // 清理之前：這張照片雖然真的存在於資料庫，但因為批次還沒提交完成，一般
        // 列表關聯完全看不到它，不會有任何使用者曾經看過它。
        $this->assertSame(0, $vehicle->photos()->whereKey($leftoverPhoto->id)->count());

        $result = $service->abandonStaleIncompleteUploadBatches();

        $this->assertSame(['abandoned' => 1, 'failed' => 0], $result);
        $this->assertSame(0, VehiclePhoto::where('id', $leftoverPhoto->id)->count());
        $this->assertSame(1, VehiclePhoto::onlyTrashed()->where('id', $leftoverPhoto->id)->count());
        $this->assertDatabaseMissing('vehicle_photo_upload_batches', ['id' => $batch->id]);
    }

    /**
     * 對照上一個測試（也是 Codex adversarial review 這一輪指出的核心情境）：
     * uploadPhotos() 逐檔建立照片的過程中，這批照片必須完全不可見；整批全部
     * 成功、真正提交完成後，才能一次性、全部一起出現在 $vehicle->photos()
     * （一般列表、public API、setCover、reorder 共用的同一個關聯）裡，不會有
     * 「部分檔案已上傳成功，看起來像成功了一部分」的中間可見狀態。
     */
    public function test_upload_photos_hides_photos_until_the_whole_batch_finishes_then_reveals_them_together(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $key = 'test-upload-key-'.uniqid();

        $realProcessor = app(VehiclePhotoImageProcessor::class);
        $seenIdsAfterFirstFile = null;
        $processor = Mockery::mock(VehiclePhotoImageProcessor::class);
        $processor->shouldReceive('process')
            ->twice()
            ->andReturnUsing(function ($file, $vehicleId) use ($realProcessor, &$seenIdsAfterFirstFile, $vehicle) {
                if ($seenIdsAfterFirstFile === null) {
                    // 第二個檔案開始處理之前，第一個檔案的 VehiclePhoto row 已經
                    // commit 在資料庫裡了，這裡直接檢查此刻透過一般列表關聯看得到
                    // 幾張照片——必須是 0，因為整批還沒完成、不該有任何一張提前
                    // 曝光。
                    $seenIdsAfterFirstFile = $vehicle->photos()->count();
                }

                return $realProcessor->process($file, $vehicleId);
            });
        $processor->shouldReceive('delete')->andReturnUsing(fn (...$args) => $realProcessor->delete(...$args));
        $this->app->instance(VehiclePhotoImageProcessor::class, $processor);

        $service = app(VehiclePhotoService::class);
        $result = $service->uploadPhotos(
            $vehicle,
            $user,
            [$this->fakeJpegUploadedFile('a.jpg'), $this->fakeJpegUploadedFile('b.jpg', 500, 500)],
            $key,
        );

        $this->assertSame(0, $seenIdsAfterFirstFile);
        $this->assertCount(2, $result);
        // 整批成功後，兩張照片必須同時、一起出現在一般列表關聯裡。
        $this->assertSame(2, $vehicle->photos()->count());
        $this->assertSame(0, VehiclePhoto::where('vehicle_id', $vehicle->id)->whereNotNull('upload_batch_id')->count());
    }

    /**
     * 對照上一個測試：租約過期時間還沒超過永久放棄門檻時，不能被誤判成已放棄——
     * 那個範圍內仍然是「允許續傳認領」的正常等待期，不該清掉使用者可能還會回來
     * 完成的上傳。
     */
    public function test_abandon_stale_incomplete_upload_batches_ignores_batches_within_sweep_threshold(): void
    {
        Storage::fake('public');
        config(['vehicle_photos.upload_batch_abandon_sweep_seconds' => 3600]);

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);

        $photo = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/recent.webp',
            'vehicles/'.$vehicle->id.'/recent_thumb.webp',
        );

        $batch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => 'test-upload-key-'.uniqid(),
            'idempotency_payload' => json_encode([
                'vehicle_id' => $vehicle->id,
                'files' => [
                    ['sha256' => 'a', 'size' => 1, 'original_filename' => 'a.jpg'],
                    ['sha256' => 'b', 'size' => 1, 'original_filename' => 'b.jpg'],
                ],
            ], JSON_THROW_ON_ERROR),
            'photo_ids' => [$photo->id],
            // 租約才剛過期 60 秒，遠低於 3600 秒的永久放棄門檻，仍是正常的續傳
            // 等待期。
            'processing_lease_expires_at' => now()->subSeconds(60),
        ]);

        $result = $service->abandonStaleIncompleteUploadBatches();

        $this->assertSame(['abandoned' => 0, 'failed' => 0], $result);
        $this->assertSame(1, VehiclePhoto::where('id', $photo->id)->count());
        $this->assertDatabaseHas('vehicle_photo_upload_batches', ['id' => $batch->id]);
    }

    /**
     * 已完成的批次（photo_ids 長度已達檔案總數）即使租約早已過期，也不代表放棄，
     * 只是完成後沒有特別清空租約而已；這種批次要繼續留著供未來相同
     * idempotency_key 的請求回放，不能被永久放棄清理誤刪。
     */
    public function test_abandon_stale_incomplete_upload_batches_ignores_completed_batches(): void
    {
        Storage::fake('public');
        config(['vehicle_photos.upload_batch_abandon_sweep_seconds' => 60]);

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);

        $photo = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/done.webp',
            'vehicles/'.$vehicle->id.'/done_thumb.webp',
        );

        $batch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => 'test-upload-key-'.uniqid(),
            'idempotency_payload' => json_encode([
                'vehicle_id' => $vehicle->id,
                'files' => [
                    ['sha256' => 'a', 'size' => 1, 'original_filename' => 'a.jpg'],
                ],
            ], JSON_THROW_ON_ERROR),
            'photo_ids' => [$photo->id],
            'processing_lease_expires_at' => now()->subDay(),
        ]);
        DB::table('vehicle_photo_upload_batches')->where('id', $batch->id)->update(['updated_at' => now()->subDay()]);

        $result = $service->abandonStaleIncompleteUploadBatches();

        $this->assertSame(['abandoned' => 0, 'failed' => 0], $result);
        $this->assertSame(1, VehiclePhoto::where('id', $photo->id)->count());
        $this->assertDatabaseHas('vehicle_photo_upload_batches', ['id' => $batch->id]);
    }

    /**
     * Codex adversarial review（第十輪）指出：processing_lease_expires_at 在一般
     * 失敗的復原路徑與整批成功後都會被明確清成 `null`，代表「立即可續傳」，但這
     * 不等於「已經放棄」。若候選查詢只挑 processing_lease_expires_at 不為 null 且
     * 早於門檻的批次，所有租約已清空的未完成批次會永久跳過清理，即使已經幾天
     * 沒有任何人回來續傳。這裡驗證 processing_lease_expires_at 為 `null`、但
     * updated_at 早已遠超過永久放棄門檻的批次，一樣會被正確清理。
     */
    public function test_abandon_stale_incomplete_upload_batches_sweeps_batches_with_null_lease_left_over_from_a_failed_retry(): void
    {
        Storage::fake('public');
        config(['vehicle_photos.upload_batch_abandon_sweep_seconds' => 60]);

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);

        $leftoverPhoto = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/null-lease.webp',
            'vehicles/'.$vehicle->id.'/null-lease_thumb.webp',
        );

        $batch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => 'test-upload-key-'.uniqid(),
            'idempotency_payload' => json_encode([
                'vehicle_id' => $vehicle->id,
                'files' => [
                    ['sha256' => 'a', 'size' => 1, 'original_filename' => 'a.jpg'],
                    ['sha256' => 'b', 'size' => 1, 'original_filename' => 'b.jpg'],
                ],
            ], JSON_THROW_ON_ERROR),
            'photo_ids' => [$leftoverPhoto->id],
            // 模擬一般失敗復原路徑（或整批成功）之後的正常狀態：租約已被明確清空，
            // 而不是撐到自然過期。
            'processing_lease_expires_at' => null,
        ]);
        // 模擬「早已放置很久沒有任何人回來續傳」。
        DB::table('vehicle_photo_upload_batches')->where('id', $batch->id)->update(['updated_at' => now()->subDay()]);

        $result = $service->abandonStaleIncompleteUploadBatches();

        $this->assertSame(['abandoned' => 1, 'failed' => 0], $result);
        $this->assertSame(0, VehiclePhoto::where('id', $leftoverPhoto->id)->count());
        $this->assertSame(1, VehiclePhoto::onlyTrashed()->where('id', $leftoverPhoto->id)->count());
        $this->assertDatabaseMissing('vehicle_photo_upload_batches', ['id' => $batch->id]);
    }

    /**
     * 對照上一個測試：租約為 null 但 updated_at 還很新（剛失敗、可能幾秒後就會被
     * 使用者重試）不能被誤判成放棄。
     */
    public function test_abandon_stale_incomplete_upload_batches_ignores_recently_updated_batches_with_null_lease(): void
    {
        Storage::fake('public');
        config(['vehicle_photos.upload_batch_abandon_sweep_seconds' => 3600]);

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);

        $photo = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/just-failed.webp',
            'vehicles/'.$vehicle->id.'/just-failed_thumb.webp',
        );

        $batch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => 'test-upload-key-'.uniqid(),
            'idempotency_payload' => json_encode([
                'vehicle_id' => $vehicle->id,
                'files' => [
                    ['sha256' => 'a', 'size' => 1, 'original_filename' => 'a.jpg'],
                    ['sha256' => 'b', 'size' => 1, 'original_filename' => 'b.jpg'],
                ],
            ], JSON_THROW_ON_ERROR),
            'photo_ids' => [$photo->id],
            'processing_lease_expires_at' => null,
        ]);

        $result = $service->abandonStaleIncompleteUploadBatches();

        $this->assertSame(['abandoned' => 0, 'failed' => 0], $result);
        $this->assertSame(1, VehiclePhoto::where('id', $photo->id)->count());
        $this->assertDatabaseHas('vehicle_photo_upload_batches', ['id' => $batch->id]);
    }

    /**
     * Codex adversarial review（第九輪）指出：先前版本的候選清單是在進入清理
     * transaction「之前」查出來的，若在這段時間差裡有一個真正的使用者請求帶著
     * 同一把 idempotency_key 回來續傳認領（核發新租約、可能已經完成整批上傳），
     * 清理仍會照著候選清單掃描當下的舊快照，刪掉這個真實請求已經完成、甚至已經
     * 回傳給使用者的照片與 batch 紀錄。這裡直接驗證 reclaimIfStillAbandoned()
     * 在真正拿到 lock、重新讀取當下狀態時，會偵測到「已經不再符合放棄條件」並
     * 安全跳過，不做任何事——模擬候選清單掃描完成之後、清理 transaction 真正執行
     * 之前，這筆批次已經被續傳認領（租約被重新核發成未來時間）的競態窗口。
     */
    public function test_reclaim_if_still_abandoned_skips_batch_reclaimed_by_a_real_retry_after_candidate_scan(): void
    {
        Storage::fake('public');
        config(['vehicle_photos.upload_batch_abandon_sweep_seconds' => 60]);

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);

        $leftoverPhoto = $this->makePhoto(
            $vehicle,
            $user,
            'vehicles/'.$vehicle->id.'/reclaimed.webp',
            'vehicles/'.$vehicle->id.'/reclaimed_thumb.webp',
        );

        $batch = VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => 'test-upload-key-'.uniqid(),
            'idempotency_payload' => json_encode([
                'vehicle_id' => $vehicle->id,
                'files' => [
                    ['sha256' => 'a', 'size' => 1, 'original_filename' => 'a.jpg'],
                    ['sha256' => 'b', 'size' => 1, 'original_filename' => 'b.jpg'],
                ],
            ], JSON_THROW_ON_ERROR),
            'photo_ids' => [$leftoverPhoto->id],
            'processing_lease_expires_at' => now()->subDay(),
        ]);

        // 這裡計算的 cutoff 對應「候選清單掃描當下」；批次在被掃描進候選清單之後
        // （scan 完成之後、真正的清理 transaction 執行之前），模擬一個真實的使用者
        // 請求帶著同一把 idempotency_key 回來續傳認領：核發新租約（未來時間）。
        $cutoff = now()->subSeconds((int) config('vehicle_photos.upload_batch_abandon_sweep_seconds'));
        $batch->update(['processing_lease_expires_at' => now()->addMinutes(30)]);

        $reflection = new \ReflectionMethod(VehiclePhotoService::class, 'reclaimIfStillAbandoned');
        $reflection->setAccessible(true);
        $outcome = $reflection->invoke($service, $batch->id, $cutoff);

        $this->assertSame('skipped', $outcome);

        // 被續傳認領的批次與其照片完全沒有被動過。
        $this->assertDatabaseHas('vehicle_photo_upload_batches', ['id' => $batch->id]);
        $this->assertSame(1, VehiclePhoto::where('id', $leftoverPhoto->id)->count());
        $this->assertSame(0, VehiclePhoto::onlyTrashed()->where('id', $leftoverPhoto->id)->count());
    }
}
