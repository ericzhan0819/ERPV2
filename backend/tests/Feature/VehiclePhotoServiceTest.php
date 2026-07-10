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
     * 若這批上傳整體失敗（例如某個檔案處理中途出錯），reservation row 必須一併清除，
     * 讓使用者用同一把 idempotency_key 重新送出時能被當成全新的第一次嘗試乾淨地
     * 重試，而不是被 beginUploadBatch() 誤判成「上一次仍在處理中」而永遠卡住。
     */
    public function test_upload_photos_clears_reservation_after_failure_so_same_key_can_retry(): void
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

        $this->assertSame(0, VehiclePhotoUploadBatch::where('idempotency_key', $key)->count());

        $this->app->forgetInstance(VehiclePhotoImageProcessor::class);
        $retryService = app(VehiclePhotoService::class);
        $result = $retryService->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile()], $key);

        $this->assertCount(1, $result);
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    /**
     * Codex stop-time review 指出：所有照片都成功建立、commit 之後，最後把 photo_ids
     * 寫回 reservation row 的收尾 update() 若失敗，先前版本會讓例外直接往外拋——
     * 使用者會看到「上傳失敗」，但照片其實已經真的建立好，且 batch 會永遠卡在
     * payload 相符、photo_ids 卻是 null 的狀態，之後任何用同一把 idempotency_key
     * 的重試都會被誤判成「仍在處理中」而永久卡死，沒有任何後續請求能修復。這裡驗證
     * 修正後的行為：收尾更新失敗仍會回傳這次真正建立的照片，並清除 reservation row，
     * 讓同一把 key 之後仍可以繼續使用（不會永久卡死）。
     */
    public function test_upload_photos_survives_batch_finalization_failure_without_wedging_the_key(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $service = app(VehiclePhotoService::class);
        $key = 'test-upload-key-'.uniqid();

        VehiclePhotoUploadBatch::updating(function () {
            throw new RuntimeException('模擬 batch 收尾更新失敗');
        });

        try {
            $result = $service->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile()], $key);
        } finally {
            VehiclePhotoUploadBatch::flushEventListeners();
        }

        // 儘管收尾更新失敗，照片本身已經真的建立成功，不能讓呼叫端看到假的失敗結果。
        $this->assertCount(1, $result);
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());

        // reservation row 必須被清除，否則同一把 key 會被誤判成「仍在處理中」而永久卡死。
        $this->assertSame(0, VehiclePhotoUploadBatch::where('idempotency_key', $key)->count());

        // 同一把 key 之後仍可以正常重試，不會永久失效（這個極罕見的雙重失敗窗口內，
        // 代價是可能重複建立這批照片，遠優於讓 key 永久卡死）。
        $retryResult = $service->uploadPhotos($vehicle, $user, [$this->fakeJpegUploadedFile()], $key);
        $this->assertCount(1, $retryResult);
        $this->assertSame(2, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }
}
