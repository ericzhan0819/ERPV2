<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * 負責把單張上傳檔轉成「可安全公開展示」的 webp 主圖 + 縮圖，並寫入 vehicle_photos
 * 設定的 disk。所有輸出一律重新編碼、strip EXIF/GPS，不直接使用使用者上傳的原檔或
 * 原始檔名（企劃書_v1.2.md 第 5~6 節）。
 */
class VehiclePhotoImageProcessor
{
    private readonly ImageManager $manager;

    public function __construct()
    {
        $this->manager = ImageManager::gd();
    }

    /**
     * @return array{disk: string, path: string, thumbnail_path: string, original_filename: string,
     *     mime_type: string, size: int, width: int, height: int}
     */
    public function process(UploadedFile $file, int $vehicleId): array
    {
        $this->assertValidImage($file);

        try {
            $source = $this->manager->read($file->getRealPath());
        } catch (DecoderException) {
            throw ValidationException::withMessages([
                'photos' => '圖片檔案無法讀取或已損毀。',
            ]);
        }

        $config = config('vehicle_photos');
        $disk = $config['disk'];
        $uuid = (string) Str::uuid();
        $path = "vehicles/{$vehicleId}/{$uuid}.webp";
        $thumbnailPath = "vehicles/{$vehicleId}/{$uuid}_thumb.webp";

        $display = $this->fitWithin(clone $source, $config['display']['max_width'], $config['display']['max_height']);
        $displayEncoded = $display->toWebp(quality: $config['display']['quality'], strip: true);

        $thumbnail = $this->fitWithin($source, $config['thumbnail']['max_width'], $config['thumbnail']['max_height']);
        $thumbnailEncoded = $thumbnail->toWebp(quality: $config['thumbnail']['quality'], strip: true);

        $this->putOrCleanup($disk, $path, (string) $displayEncoded, $thumbnailPath, (string) $thumbnailEncoded);

        return [
            'disk' => $disk,
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => 'image/webp',
            'size' => strlen((string) $displayEncoded),
            'width' => $display->width(),
            'height' => $display->height(),
        ];
    }

    /**
     * 刪除照片檔案，缺檔視為已完成（idempotent），不因檔案早已不存在而報錯。
     */
    public function delete(string $disk, string $path, ?string $thumbnailPath): void
    {
        $storage = Storage::disk($disk);

        if ($storage->exists($path)) {
            $storage->delete($path);
        }

        if ($thumbnailPath !== null && $storage->exists($thumbnailPath)) {
            $storage->delete($thumbnailPath);
        }
    }

    private function fitWithin(ImageInterface $image, int $maxWidth, int $maxHeight): ImageInterface
    {
        if ($image->width() > $maxWidth || $image->height() > $maxHeight) {
            return $image->scaleDown($maxWidth, $maxHeight);
        }

        return $image;
    }

    private function putOrCleanup(string $disk, string $path, string $contents, string $thumbnailPath, string $thumbnailContents): void
    {
        $storage = Storage::disk($disk);
        $writtenPath = null;
        $writtenThumbnailPath = null;

        try {
            if (! $storage->put($path, $contents)) {
                throw new \RuntimeException('車輛照片主圖儲存失敗。');
            }
            $writtenPath = $path;

            if (! $storage->put($thumbnailPath, $thumbnailContents)) {
                throw new \RuntimeException('車輛照片縮圖儲存失敗。');
            }
            $writtenThumbnailPath = $thumbnailPath;
        } catch (Throwable $e) {
            if ($writtenPath !== null) {
                $storage->delete($writtenPath);
            }
            if ($writtenThumbnailPath !== null) {
                $storage->delete($writtenThumbnailPath);
            }

            throw $e;
        }
    }

    private function assertValidImage(UploadedFile $file): void
    {
        $config = config('vehicle_photos');

        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'photos' => '檔案上傳失敗，請重新選擇檔案。',
            ]);
        }

        if ($file->getSize() > $config['max_file_size_kb'] * 1024) {
            throw ValidationException::withMessages([
                'photos' => '單張照片檔案大小不可超過 8MB。',
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $config['allowed_extensions'], true)) {
            throw ValidationException::withMessages([
                'photos' => '照片格式僅接受 jpg、jpeg、png、webp。',
            ]);
        }

        // getMimeType() 是依實際檔案內容偵測，避免使用者只是把副檔名改成 .jpg
        // 但內容其實是 svg / heic / pdf 等不允許的格式。
        $detectedMime = $file->getMimeType();
        if (! in_array($detectedMime, $config['allowed_mimes'], true)) {
            throw ValidationException::withMessages([
                'photos' => '照片格式僅接受 jpg、jpeg、png、webp。',
            ]);
        }

        // 只用 getimagesize() 讀檔頭取得像素尺寸，不會像 ImageManager::read() 一樣把整張圖
        // 解碼進記憶體。8MB 以內的檔案仍可能宣告超大像素尺寸（例如極端壓縮的 PNG），若不在
        // 解碼前擋下，read() 會先把整張圖展開進記憶體才輪到 fitWithin() 縮小，等於任何請求都
        // 能透過一張合法但像素超大的圖片吃光 worker 記憶體/CPU（decompression bomb）。
        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions === false) {
            throw ValidationException::withMessages([
                'photos' => '圖片檔案無法讀取或已損毀。',
            ]);
        }

        [$width, $height] = $dimensions;
        $megapixels = ($width * $height) / 1_000_000;
        if ($megapixels > $config['max_megapixels']) {
            throw ValidationException::withMessages([
                'photos' => '照片像素尺寸過大，請使用較小尺寸的圖片。',
            ]);
        }
    }
}
