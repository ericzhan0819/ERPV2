<?php

namespace Tests\Feature;

use App\Services\VehiclePhotoImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;
use Tests\TestCase;
use WeakReference;

/**
 * v1.2 Part 2（Storage / 圖片處理）建構元件的基礎驗證。完整的上傳流程權限、
 * 每台車張數上限等測試會在 Service / Controller 完成後於 VehiclePhotoTest 補齊
 * （PLAN_v1.2.md 第 7.1 節）。
 */
class VehiclePhotoImageProcessorTest extends TestCase
{
    private function fakeJpegUploadedFile(int $width = 800, int $height = 600): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 200));

        $path = tempnam(sys_get_temp_dir(), 'vehicle_photo_test_').'.jpg';
        imagejpeg($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'phone-photo.jpg', 'image/jpeg', null, true);
    }

    public function test_process_re_encodes_to_webp_and_generates_thumbnail(): void
    {
        Storage::fake('public');

        $processor = app(VehiclePhotoImageProcessor::class);
        $result = $processor->process($this->fakeJpegUploadedFile(2400, 1800), vehicleId: 42);

        $this->assertSame('public', $result['disk']);
        $this->assertStringStartsWith('vehicles/42/', $result['path']);
        $this->assertStringEndsWith('.webp', $result['path']);
        $this->assertStringEndsWith('_thumb.webp', $result['thumbnail_path']);
        $this->assertSame('image/webp', $result['mime_type']);
        $this->assertSame('phone-photo.jpg', $result['original_filename']);

        // 超過 1920 上限應被縮小，不可直接保留手機原圖尺寸
        $this->assertLessThanOrEqual(1920, $result['width']);
        $this->assertLessThanOrEqual(1920, $result['height']);

        Storage::disk('public')->assertExists($result['path']);
        Storage::disk('public')->assertExists($result['thumbnail_path']);
    }

    public function test_process_rejects_disallowed_extension(): void
    {
        Storage::fake('public');

        $path = tempnam(sys_get_temp_dir(), 'vehicle_photo_test_').'.svg';
        file_put_contents($path, '<svg></svg>');
        $file = new UploadedFile($path, 'evil.svg', 'image/svg+xml', null, true);

        $processor = app(VehiclePhotoImageProcessor::class);

        $this->expectException(ValidationException::class);
        $processor->process($file, vehicleId: 42);
    }

    public function test_process_rejects_images_over_configured_megapixel_limit(): void
    {
        Storage::fake('public');

        // 用小張測試圖 + 調低門檻來驗證「解碼前依像素數擋下」的邏輯，
        // 不需要真的產生超大圖片（那樣做本身就有測試環境資源風險）。
        config(['vehicle_photos.max_megapixels' => 0.1]);

        $processor = app(VehiclePhotoImageProcessor::class);

        $this->expectException(ValidationException::class);
        $processor->process($this->fakeJpegUploadedFile(800, 600), vehicleId: 42);
    }

    public function test_default_cap_accepts_common_phone_resolution(): void
    {
        Storage::fake('public');

        // 不覆寫 config，直接用正式預設值驗證：4032x3024 是 iPhone 常見預設拍照解析度
        // （約 12.19MP），也是 config 註解裡拿來當門檻依據的實測案例本身，必須真的能
        // 通過正式門檻，不能只是「差不多接近」。
        $processor = app(VehiclePhotoImageProcessor::class);
        $result = $processor->process($this->fakeJpegUploadedFile(4032, 3024), vehicleId: 42);

        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_default_cap_accepts_high_resolution_camera_output(): void
    {
        Storage::fake('public');

        // 不覆寫 config，用 6000x4000（剛好 24MP，也是 config 註解裡拿來當門檻依據的
        // 實測案例本身）驗證正式門檻真的涵蓋一般相機/高階手機的直出解析度，而不是
        // 連帶把設計目的（縮小存放高畫素照片）本身也擋掉。
        $processor = app(VehiclePhotoImageProcessor::class);
        $result = $processor->process($this->fakeJpegUploadedFile(6000, 4000), vehicleId: 42);

        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_default_cap_rejects_image_above_measured_safe_threshold(): void
    {
        Storage::fake('public');

        // 不覆寫 config，用超過正式 24MP 門檻的壓縮圖片（6200x4000 = 24.8MP）驗證
        // 生產設定值真的會擋下，而不是只在單元測試裡人為調低門檻才擋得住。
        $processor = app(VehiclePhotoImageProcessor::class);

        $this->expectException(ValidationException::class);
        $processor->process($this->fakeJpegUploadedFile(6200, 4000), vehicleId: 42);
    }

    public function test_process_rejects_when_another_upload_is_already_processing(): void
    {
        Storage::fake('public');

        // 模擬另一個請求正在處理照片（持有全域 lock），驗證這個「同時間只解碼/編碼
        // 一張圖」的保證真的有生效，而不是只有註解說有。wait 設 0 秒讓測試不用真的等。
        config(['vehicle_photos.processing_lock_wait_seconds' => 0]);

        $heldByOtherRequest = Cache::lock('vehicle_photo_image_processing', 5);
        $this->assertTrue($heldByOtherRequest->get());

        try {
            $processor = app(VehiclePhotoImageProcessor::class);

            $this->expectException(ValidationException::class);
            $processor->process($this->fakeJpegUploadedFile(800, 600), vehicleId: 42);
        } finally {
            $heldByOtherRequest->release();
        }
    }

    public function test_process_succeeds_once_lock_is_free_again(): void
    {
        Storage::fake('public');

        $processor = app(VehiclePhotoImageProcessor::class);
        $result = $processor->process($this->fakeJpegUploadedFile(800, 600), vehicleId: 42);

        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_processing_aborts_if_lock_lease_is_lost_mid_flight(): void
    {
        Storage::fake('public');

        $processor = app(VehiclePhotoImageProcessor::class);
        $config = config('vehicle_photos');

        $lock = Cache::lock('vehicle_photo_image_processing', 60);
        $this->assertTrue($lock->get());

        // 模擬「lease 在處理途中真的過期、被搶走或被清掉」：直接讓底層 row 消失，
        // 使 refresh() 因為找不到符合 owner 的 row 而回傳 false。驗證的是：處理途中
        // 一旦偵測到已經失去獨佔權，一定要立刻中止，不能假裝還擁有獨佔權繼續把檔案
        // 寫進 storage（Codex adversarial review 指出：固定 TTL 沒有續約機制的話，
        // 處理變慢時第二個請求可能誤以為自己也拿到了獨佔權）。
        $lock->forceRelease();

        $assertLockStillHeld = new \ReflectionMethod($processor, 'assertLockStillHeld');
        $assertLockStillHeld->setAccessible(true);

        $this->expectException(ValidationException::class);
        $assertLockStillHeld->invoke($processor, $lock, $config);
    }

    public function test_intervention_image_objects_are_actually_freed_when_unset(): void
    {
        // VehiclePhotoImageProcessor::decodeAndStore() 在編碼完成、進入 storage I/O 前
        // 會 unset 掉解碼用的 Image 物件，理由是讓底層 GD 原生記憶體立刻釋放，不要在
        // 可能卡住的 I/O 期間繼續占著（Codex adversarial review 第七輪指出：不這樣做
        // 的話，lock lease 過期時還是可能跟另一個請求同時持有原生 GD buffer）。這個
        // 優化成立的前提是「unset 之後這個物件真的沒有其他地方持有參照、會被釋放」，
        // 不是被 Intervention/Image 內部某個靜態 registry 或快取偷偷留著。這裡直接用
        // WeakReference 驗證這個前提，避免以後升級套件版本，這個假設不知不覺失效。
        $file = $this->fakeJpegUploadedFile(800, 600);
        $manager = ImageManager::gd();
        $image = $manager->read($file->getRealPath());

        $ref = WeakReference::create($image);
        $this->assertNotNull($ref->get());

        unset($image);
        gc_collect_cycles();

        $this->assertNull($ref->get());
    }

    public function test_delete_is_idempotent_when_files_already_missing(): void
    {
        Storage::fake('public');

        $processor = app(VehiclePhotoImageProcessor::class);

        // 檔案本來就不存在時，delete 不可拋例外
        $processor->delete('public', 'vehicles/42/missing.webp', 'vehicles/42/missing_thumb.webp');

        $this->addToAssertionCount(1);
    }
}
