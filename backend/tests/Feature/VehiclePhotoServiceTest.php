<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Services\VehiclePhotoImageProcessor;
use App\Services\VehiclePhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
}
