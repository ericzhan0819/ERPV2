<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Models\VehiclePhotoUploadBatch;
use App\Services\VehiclePhotoImageProcessor;
use App\Services\VehiclePhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    private function makePhoto(Vehicle $vehicle, User $user, string $path, string $thumbnailPath): VehiclePhoto
    {
        return VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
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
     * Codex stop-time review 指出：所有照片都成功建立、commit 之後，最後的收尾動作
     * （清空 processing_lease_expires_at）若失敗，不能讓例外直接往外拋——使用者會
     * 看到「上傳失敗」，但照片其實已經真的建立好。這裡驗證修正後的行為：收尾失敗
     * 仍會回傳這次真正建立的照片，且完成判斷只看 photo_ids 長度、不依賴租約是否
     * 成功清空，之後同一把 key 仍可以正常回放。
     */
    public function test_upload_photos_survives_clear_processing_lease_failure_after_success(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);
        $key = 'test-upload-key-'.uniqid();

        // 單一檔案的成功流程會對 batch 呼叫兩次 update()：(1) 檔案處理完成當下同步
        // 寫回 photo_ids，(2) 整批完成後收尾清空租約。只讓第 2 次失敗，模擬「照片
        // 已經真的建立成功，只有收尾清空租約這個衛生動作失敗」。
        $updateCallCount = 0;
        VehiclePhotoUploadBatch::updating(function () use (&$updateCallCount) {
            $updateCallCount++;
            if ($updateCallCount === 2) {
                throw new RuntimeException('模擬批次收尾清空租約失敗');
            }
        });

        try {
            $result = $service->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile()], $key);
        } finally {
            VehiclePhotoUploadBatch::flushEventListeners();
        }

        // 儘管收尾清空租約失敗，照片本身已經真的建立成功，不能讓呼叫端看到假的失敗結果。
        $this->assertCount(1, $result);
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());

        // 同一把 key 之後仍可以正常回放，不受收尾失敗影響、也不會重複建立照片。
        $retryResult = $service->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile()], $key);
        $this->assertSame($result->pluck('id')->all(), $retryResult->pluck('id')->all());
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
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
}
