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
