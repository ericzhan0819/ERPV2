<?php

namespace Tests\Feature;

use App\Services\VehiclePhotoImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

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

    public function test_default_cap_rejects_image_above_measured_safe_threshold(): void
    {
        Storage::fake('public');

        // 不覆寫 config，用超過正式 13MP 門檻的壓縮圖片（4200x3100 ≈ 13.02MP）驗證
        // 生產設定值真的會擋下，而不是只在單元測試裡人為調低門檻才擋得住。
        $processor = app(VehiclePhotoImageProcessor::class);

        $this->expectException(ValidationException::class);
        $processor->process($this->fakeJpegUploadedFile(4200, 3100), vehicleId: 42);
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
