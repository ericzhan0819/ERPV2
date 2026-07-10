<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
    public function uploadPhotos(Vehicle $vehicle, User $user, array $files): Collection
    {
        $config = config('vehicle_photos');

        if (count($files) > $config['max_files_per_upload']) {
            throw ValidationException::withMessages([
                'photos' => "單次上傳最多 {$config['max_files_per_upload']} 張照片。",
            ]);
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
                $this->processor->delete($photo->disk, $photo->path, $photo->thumbnail_path);
                $photo->delete();
            }

            throw $e;
        }

        return new Collection($created);
    }

    public function deletePhoto(Vehicle $vehicle, VehiclePhoto $photo): void
    {
        $this->assertBelongsToVehicle($vehicle, $photo);

        DB::transaction(function () use ($vehicle, $photo) {
            Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

            // $photo 是進入 transaction 前、拿到 row lock 前就載入的實例，若在「載入」
            // 與「拿到 lock」之間有另一個並發請求呼叫 setCover() 把封面換成這張照片，
            // 這裡的 $photo->is_cover 會是過期的 false，導致以為刪除的不是封面、
            // 不去補封面，讓車輛最終沒有任何封面照（Codex adversarial review 指出）。
            // 拿到 lock 後必須重新從 DB 讀一次目前真正的 is_cover 狀態。
            $photo->refresh();
            $wasCover = $photo->is_cover;

            $photo->delete();

            if ($wasCover) {
                $next = $vehicle->photos()->orderBy('sort_order')->first();
                if ($next !== null) {
                    $next->is_cover = true;
                    $next->save();
                }
            }
        });

        $this->processor->delete($photo->disk, $photo->path, $photo->thumbnail_path);
    }

    public function setCover(Vehicle $vehicle, VehiclePhoto $photo): VehiclePhoto
    {
        $this->assertBelongsToVehicle($vehicle, $photo);

        DB::transaction(function () use ($vehicle, $photo) {
            Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

            $vehicle->photos()
                ->where('is_cover', true)
                ->where('id', '!=', $photo->id)
                ->update(['is_cover' => false]);

            $photo->is_cover = true;
            $photo->save();
        });

        return $photo->refresh();
    }

    /**
     * @param  array<int, int>  $photoIds
     */
    public function reorder(Vehicle $vehicle, array $photoIds): Collection
    {
        $photos = $vehicle->photos()->whereIn('id', $photoIds)->get()->keyBy('id');

        if ($photos->count() !== count($photoIds) || $photos->count() !== count(array_unique($photoIds))) {
            throw ValidationException::withMessages([
                'photo_ids' => '排序清單包含不屬於此車輛或重複的照片。',
            ]);
        }

        DB::transaction(function () use ($vehicle, $photoIds, $photos) {
            Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

            foreach ($photoIds as $index => $photoId) {
                $photos[$photoId]->update(['sort_order' => $index]);
            }
        });

        return $vehicle->photos()->get();
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
