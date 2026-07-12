<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehiclePhotoTest extends TestCase
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

    private function makePhoto(Vehicle $vehicle, User $user, string $suffix, bool $isCover = false, int $sortOrder = 0): VehiclePhoto
    {
        return VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'disk' => 'public',
            'path' => 'vehicles/'.$vehicle->id.'/'.$suffix.'.webp',
            'thumbnail_path' => 'vehicles/'.$vehicle->id.'/'.$suffix.'_thumb.webp',
            'original_filename' => $suffix.'.jpg',
            'mime_type' => 'image/webp',
            'size' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => $sortOrder,
            'is_cover' => $isCover,
            'uploaded_by' => $user->id,
        ]);
    }

    public function test_admin_can_upload_photos(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        $response = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'admin-upload-'.uniqid(),
            'photos' => [$this->fakeJpegUploadedFile()],
        ]);

        $response->assertOk();
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_manager_can_upload_photos(): void
    {
        Storage::fake('public');

        $manager = User::factory()->manager()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        $response = $this->actingAs($manager, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'manager-upload-'.uniqid(),
            'photos' => [$this->fakeJpegUploadedFile()],
        ]);

        $response->assertOk();
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_sales_cannot_upload_photos(): void
    {
        Storage::fake('public');

        $sales = User::factory()->sales()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        $response = $this->actingAs($sales, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'sales-upload-'.uniqid(),
            'photos' => [$this->fakeJpegUploadedFile()],
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_guest_cannot_upload_photos(): void
    {
        Storage::fake('public');

        $vehicle = Vehicle::factory()->create();

        $response = $this->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'guest-upload-'.uniqid(),
            'photos' => [$this->fakeJpegUploadedFile()],
        ]);

        $response->assertStatus(401);
        $this->assertSame(0, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_sales_can_read_photo_list(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $this->makePhoto($vehicle, $admin, 'a', true);

        $response = $this->actingAs($sales, 'web')->getJson("/api/vehicles/{$vehicle->id}/photos");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_first_uploaded_photo_becomes_cover_automatically(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        $response = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'first-upload-'.uniqid(),
            'photos' => [$this->fakeJpegUploadedFile()],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.0.is_cover', true);
    }

    public function test_second_and_third_uploaded_photos_do_not_become_cover(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'batch-1-'.uniqid(),
            'photos' => [$this->fakeJpegUploadedFile('a.jpg')],
        ])->assertOk();

        $response = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'batch-2-'.uniqid(),
            'photos' => [
                $this->fakeJpegUploadedFile('b.jpg', 500, 500),
                $this->fakeJpegUploadedFile('c.jpg', 600, 600),
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.0.is_cover', false);
        $response->assertJsonPath('data.1.is_cover', false);
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->where('is_cover', true)->count());
    }

    public function test_setting_cover_manually_unsets_other_covers_on_same_vehicle(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $first = $this->makePhoto($vehicle, $admin, 'a', true, 0);
        $second = $this->makePhoto($vehicle, $admin, 'b', false, 1);

        $response = $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicle->id}/photos/{$second->id}/cover");

        $response->assertOk();
        $response->assertJsonPath('data.is_cover', true);
        $this->assertFalse($first->fresh()->is_cover);
        $this->assertTrue($second->fresh()->is_cover);
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->where('is_cover', true)->count());
    }

    public function test_deleting_cover_photo_automatically_promotes_next_photo(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $cover = $this->makePhoto($vehicle, $admin, 'a', true, 0);
        $next = $this->makePhoto($vehicle, $admin, 'b', false, 1);

        $response = $this->actingAs($admin, 'web')->deleteJson("/api/vehicles/{$vehicle->id}/photos/{$cover->id}");

        $response->assertOk();
        $this->assertTrue($next->fresh()->is_cover);
        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_photos_can_be_reordered(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $first = $this->makePhoto($vehicle, $admin, 'a', true, 0);
        $second = $this->makePhoto($vehicle, $admin, 'b', false, 1);

        $response = $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicle->id}/photos/reorder", [
            'photo_ids' => [$second->id, $first->id],
        ]);

        $response->assertOk();
        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
    }

    public function test_uploading_photo_writes_audit_log_entry(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'audit-upload-'.uniqid(),
            'photos' => [$this->fakeJpegUploadedFile()],
        ])->assertOk();

        $photo = VehiclePhoto::where('vehicle_id', $vehicle->id)->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => AuditLog::ACTION_CREATED,
            'subject_type' => 'vehicle_photo',
            'subject_id' => $photo->id,
        ]);
    }

    public function test_idempotent_replay_of_photo_upload_does_not_duplicate_audit_log_entry(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $key = 'audit-replay-'.uniqid();

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => $key,
            'photos' => [$this->fakeJpegUploadedFile()],
        ])->assertOk();

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => $key,
            'photos' => [$this->fakeJpegUploadedFile()],
        ])->assertOk();

        $this->assertSame(
            1,
            AuditLog::query()->where('subject_type', 'vehicle_photo')->where('action', AuditLog::ACTION_CREATED)->count(),
        );
    }

    public function test_deleting_photo_writes_audit_log_entry(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $photo = $this->makePhoto($vehicle, $admin, 'a', true, 0);

        $this->actingAs($admin, 'web')->deleteJson("/api/vehicles/{$vehicle->id}/photos/{$photo->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => AuditLog::ACTION_DELETED,
            'subject_type' => 'vehicle_photo',
            'subject_id' => $photo->id,
        ]);
    }

    public function test_setting_cover_writes_audit_log_entry(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $this->makePhoto($vehicle, $admin, 'a', true, 0);
        $second = $this->makePhoto($vehicle, $admin, 'b', false, 1);

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicle->id}/photos/{$second->id}/cover")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => AuditLog::ACTION_UPDATED,
            'subject_type' => 'vehicle_photo',
            'subject_id' => $second->id,
        ]);

        // Codex adversarial review 指出：setCover() 在 save() 已經回傳之後才呼叫
        // recordModelEvent()，此時 Eloquent 已經把 getRawOriginal() 同步成新值，
        // 若沒有先擷取 save() 之前的原始值，這裡記下的 before_values.is_cover 會
        // 被誤記成跟 after_values 一樣的 true，而不是換封面前真正的 false。
        $auditLog = AuditLog::query()
            ->where('subject_type', 'vehicle_photo')
            ->where('action', AuditLog::ACTION_UPDATED)
            ->where('subject_id', $second->id)
            ->firstOrFail();

        // before_values／after_values 是從 getRawOriginal()／getChanges() 取的未經
        // is_cover cast 的原始 DB 值（0/1），不是 Eloquent boolean cast 過的值。
        $this->assertEquals(0, $auditLog->before_values['is_cover']);
        $this->assertEquals(1, $auditLog->after_values['is_cover']);
    }

    public function test_reordering_photos_writes_audit_log_entry(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $first = $this->makePhoto($vehicle, $admin, 'a', true, 0);
        $second = $this->makePhoto($vehicle, $admin, 'b', false, 1);

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicle->id}/photos/reorder", [
            'photo_ids' => [$second->id, $first->id],
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => AuditLog::ACTION_UPDATED,
            'subject_type' => 'vehicle_photo',
            'subject_id' => null,
        ]);
    }

    public function test_reorder_with_photo_id_from_another_vehicle_is_rejected(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicleA = Vehicle::factory()->create();
        $vehicleB = Vehicle::factory()->create();
        $photoA = $this->makePhoto($vehicleA, $admin, 'a', true, 0);
        $photoB = $this->makePhoto($vehicleB, $admin, 'b', true, 0);

        $response = $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicleA->id}/photos/reorder", [
            'photo_ids' => [$photoA->id, $photoB->id],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, $photoA->fresh()->sort_order);
    }

    public function test_deleting_photo_removes_db_row_and_storage_files(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $photo = $this->makePhoto($vehicle, $admin, 'a', true, 0);
        Storage::disk('public')->put($photo->path, 'bytes');
        Storage::disk('public')->put($photo->thumbnail_path, 'thumb-bytes');

        $response = $this->actingAs($admin, 'web')->deleteJson("/api/vehicles/{$vehicle->id}/photos/{$photo->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('vehicle_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing($photo->path);
        Storage::disk('public')->assertMissing($photo->thumbnail_path);
    }

    public function test_unsupported_file_format_is_rejected_with_422(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'bad-format-'.uniqid(),
            'photos' => [$file],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photos.0']);
        $this->assertSame(0, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_oversized_file_is_rejected_with_422(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $file = UploadedFile::fake()->create('huge.jpg', 9000, 'image/jpeg');

        $response = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'oversized-'.uniqid(),
            'photos' => [$file],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photos.0']);
        $this->assertSame(0, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_uploading_more_than_max_files_per_request_is_rejected_with_422(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $maxPerUpload = config('vehicle_photos.max_files_per_upload');

        $files = [];
        for ($i = 0; $i < $maxPerUpload + 1; $i++) {
            $files[] = $this->fakeJpegUploadedFile('photo-'.$i.'.jpg');
        }

        $response = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'too-many-files-'.uniqid(),
            'photos' => $files,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photos']);
        $this->assertSame(0, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_uploading_beyond_max_photos_per_vehicle_is_rejected_with_422(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $maxPerVehicle = config('vehicle_photos.max_photos_per_vehicle');

        for ($i = 0; $i < $maxPerVehicle; $i++) {
            $this->makePhoto($vehicle, $admin, 'existing-'.$i, $i === 0, $i);
        }

        $response = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'over-vehicle-limit-'.uniqid(),
            'photos' => [$this->fakeJpegUploadedFile()],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photos']);
        $this->assertSame($maxPerVehicle, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_uploading_to_full_vehicle_does_not_process_images(): void
    {
        // Codex adversarial review 指出：先前版本在檢查 max_photos_per_vehicle
        // 之前就先呼叫 processor->process() 解碼/縮圖，讓已滿的車輛可以被重複
        // 打去消耗圖片處理資源、每次都保證失敗。這裡用 spy 斷言容量預檢會在
        // process() 之前就擋掉整批請求，process() 完全不會被呼叫。
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $maxPerVehicle = config('vehicle_photos.max_photos_per_vehicle');

        for ($i = 0; $i < $maxPerVehicle; $i++) {
            $this->makePhoto($vehicle, $admin, 'existing-'.$i, $i === 0, $i);
        }

        $spy = $this->spy(\App\Services\VehiclePhotoImageProcessor::class);

        $response = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'over-vehicle-limit-no-processing-'.uniqid(),
            'photos' => [$this->fakeJpegUploadedFile()],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photos']);
        $spy->shouldNotHaveReceived('process');
        $this->assertSame($maxPerVehicle, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_capacity_rejection_releases_upload_batch_lease_for_immediate_retry(): void
    {
        // Codex adversarial review 第二輪指出：容量預檢若丟在 try 區塊外面，
        // 會繞過既有的 batch 復原/清租約邏輯，讓這把 idempotency_key 的
        // processing lease 卡在未過期狀態，直到 TTL 自然到期前，同一把 key
        // 的合法重試都會被誤判成「仍在處理中」而被拒絕。這裡驗證容量預檢
        // 失敗後，同一把 idempotency_key 可以立刻重試（不會卡在 lease 未清空
        // 的狀態），且第二次請求依然正確地因為容量已滿被拒絕，而不是被誤判
        // 成「仍在處理中」。
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $maxPerVehicle = config('vehicle_photos.max_photos_per_vehicle');

        for ($i = 0; $i < $maxPerVehicle; $i++) {
            $this->makePhoto($vehicle, $admin, 'existing-'.$i, $i === 0, $i);
        }

        $idempotencyKey = 'over-vehicle-limit-retry-'.uniqid();

        // 兩次請求必須是完全相同的檔案內容（同一份 sha256），才會落在
        // beginUploadBatch() 的「payload 相符」分支，進而真正驗證到 lease 是否
        // 已被釋放；若內容不同，會先被「idempotency_key 已用於內容不同的請求」
        // 擋下，測不到 lease 釋放與否。這裡重複使用同一個 UploadedFile 物件：
        // 它是 test-mode UploadedFile（建構子最後一個參數 true），store 時走
        // copy 而非 move，原始暫存檔在兩次呼叫之間不會被清掉。
        $file = $this->fakeJpegUploadedFile();

        $firstResponse = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => $idempotencyKey,
            'photos' => [$file],
        ]);
        $firstResponse->assertStatus(422);
        $firstResponse->assertJsonValidationErrors(['photos']);

        $secondResponse = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => $idempotencyKey,
            'photos' => [$file],
        ]);

        $secondResponse->assertStatus(422);
        $secondResponse->assertJsonValidationErrors(['photos']);
        $this->assertSame($maxPerVehicle, VehiclePhoto::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_capacity_preflight_does_not_falsely_reject_during_concurrent_batch_rollback(): void
    {
        // Codex adversarial review 指出：容量預檢若把「其他 batch 尚未提交、
        // 還在處理中」的 hidden row 也算進目前照片數，會讓一個原本可以在那個
        // 並發請求結算（結算後被復原/soft-delete）後成功的合法請求，在真正開始
        // 處理圖片之前就被這個便宜的預檢提早、錯誤地打回 422；而按照原本沒有
        // 這道預檢的流程，這個請求會先花時間做圖片處理，再進到有 row lock 保護
        // 的 per-file transaction，那時另一個並發 batch 早已結算完畢、釋放了它
        // 的 hidden 照片，這個請求其實可以成功。
        //
        // 這裡模擬：車輛已有 max-1 張正式可見照片，另外還有 1 張屬於「別的、
        // 尚未完成」batch 的 hidden 照片（upload_batch_id 指向一個不存在於這次
        // 請求的 batch）。用一個包一層「呼叫 process() 時，把那張 hidden 照片
        // soft-delete 掉」的 processor 子類別，模擬「這次請求做圖片處理的這段
        // 時間內，另一個並發 batch 剛好失敗並完成它自己的復原」。
        //
        // 驗證重點：
        // 1. 預檢不能只因為「目前所有非刪除照片數（含其他 batch 的 hidden
        //    row）+ 這次要處理的檔案數」超過上限就直接丟 422——那樣會在
        //    processor 完全沒被呼叫之前就打回，永遠等不到並發 batch 結算。
        // 2. 這次上傳最終必須成功：圖片處理真的被呼叫過，且 per-file
        //    transaction 是在「其他 batch 的 hidden 照片已經被復原」之後才做
        //    最終容量判斷，看到的是已經釋放的容量，所以成功建立新照片。
        Storage::fake('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $maxPerVehicle = config('vehicle_photos.max_photos_per_vehicle');

        for ($i = 0; $i < $maxPerVehicle - 1; $i++) {
            $this->makePhoto($vehicle, $admin, 'existing-'.$i, $i === 0, $i);
        }

        $otherBatch = \App\Models\VehiclePhotoUploadBatch::create([
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => 'other-in-flight-batch-'.uniqid(),
            'idempotency_payload' => 'unrelated-payload',
            'photo_ids' => [],
            'processing_lease_expires_at' => now()->addMinutes(10),
            'claim_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $hiddenPhoto = $this->makePhoto($vehicle, $admin, 'hidden-in-progress', false, $maxPerVehicle - 1);
        $hiddenPhoto->update(['upload_batch_id' => $otherBatch->id]);

        $this->app->bind(\App\Services\VehiclePhotoImageProcessor::class, function () use ($hiddenPhoto) {
            return new class($hiddenPhoto) extends \App\Services\VehiclePhotoImageProcessor
            {
                private bool $settled = false;

                public function __construct(private readonly VehiclePhoto $hiddenPhoto)
                {
                    parent::__construct();
                }

                public function process(\Illuminate\Http\UploadedFile $file, int $vehicleId): array
                {
                    $data = parent::process($file, $vehicleId);

                    if (! $this->settled) {
                        $this->settled = true;
                        $this->hiddenPhoto->delete();
                    }

                    return $data;
                }
            };
        });

        $response = $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/photos", [
            'idempotency_key' => 'concurrent-with-hidden-batch-'.uniqid(),
            'photos' => [$this->fakeJpegUploadedFile()],
        ]);

        $response->assertOk();
        $this->assertSame(
            $maxPerVehicle,
            VehiclePhoto::where('vehicle_id', $vehicle->id)->count()
        );
    }
}
