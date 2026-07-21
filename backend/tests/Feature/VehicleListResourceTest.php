<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VehicleListResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makePhoto(
        Vehicle $vehicle,
        User $uploader,
        string $suffix,
        bool $isCover = false,
        ?int $uploadBatchId = null,
    ): VehiclePhoto {
        return VehiclePhoto::create([
            'vehicle_id' => $vehicle->id,
            'upload_batch_id' => $uploadBatchId,
            'disk' => 'public',
            'path' => "vehicles/{$vehicle->id}/{$suffix}.webp",
            'thumbnail_path' => "vehicles/{$vehicle->id}/{$suffix}_thumb.webp",
            'original_filename' => "{$suffix}.jpg",
            'mime_type' => 'image/webp',
            'size' => 100,
            'width' => 1200,
            'height' => 900,
            'sort_order' => 0,
            'is_cover' => $isCover,
            'uploaded_by' => $uploader->id,
        ]);
    }

    public function test_list_returns_only_the_committed_cover_thumbnail_fields(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $cover = $this->makePhoto($vehicle, $admin, 'cover', true);
        $this->makePhoto($vehicle, $admin, 'gallery');

        $response = $this->actingAs($admin, 'web')->getJson('/api/vehicles')->assertOk();

        $response
            ->assertJsonPath('data.0.cover_photo.id', $cover->id)
            ->assertJsonPath(
                'data.0.cover_photo.thumbnail_url',
                "http://localhost:8000/storage/vehicles/{$vehicle->id}/cover_thumb.webp",
            )
            ->assertJsonMissingPath('data.0.cover_photo.url')
            ->assertJsonMissingPath('data.0.cover_photo.path')
            ->assertJsonMissingPath('data.0.cover_photo.thumbnail_path')
            ->assertJsonMissingPath('data.0.cover_photo.original_filename')
            ->assertJsonMissingPath('data.0.photos');
    }

    public function test_list_returns_null_without_a_committed_cover_photo(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicleWithoutPhoto = Vehicle::factory()->create(['brand' => '無照片']);
        $vehicleWithPendingCover = Vehicle::factory()->create(['brand' => '批次未完成']);
        $batchId = DB::table('vehicle_photo_upload_batches')->insertGetId([
            'vehicle_id' => $vehicleWithPendingCover->id,
            'idempotency_key' => 'pending-cover-batch',
            'idempotency_payload' => '[]',
            'photo_ids' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->makePhoto($vehicleWithPendingCover, $admin, 'pending-cover', true, $batchId);

        $response = $this->actingAs($admin, 'web')->getJson('/api/vehicles')->assertOk();
        $vehicles = collect($response->json('data'))->keyBy('brand');

        $this->assertNull($vehicles['無照片']['cover_photo']);
        $this->assertNull($vehicles['批次未完成']['cover_photo']);
    }

    public function test_list_eager_loads_cover_photos_once_with_only_required_database_columns(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        foreach (Vehicle::factory()->count(3)->create() as $index => $vehicle) {
            $this->makePhoto($vehicle, $admin, "cover-{$index}", true);
            $this->makePhoto($vehicle, $admin, "gallery-{$index}");
        }

        DB::enableQueryLog();
        $this->actingAs($admin, 'web')->getJson('/api/vehicles')->assertOk()->assertJsonCount(3, 'data');

        $photoQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, 'from "vehicle_photos"'))
            ->values();

        $this->assertCount(1, $photoQueries);
        $this->assertStringContainsString(
            'select "id", "vehicle_id", "disk", "thumbnail_path"',
            $photoQueries->first(),
        );
        $this->assertStringNotContainsString('original_filename', $photoQueries->first());
        $this->assertStringNotContainsString('"path"', $photoQueries->first());
    }

    public function test_list_keeps_existing_role_masking_and_adds_no_financial_summary_fields(): void
    {
        $vehicle = Vehicle::factory()->create([
            'purchase_price' => 500000,
            'asking_price' => 650000,
            'floor_price' => 600000,
            'sold_price' => 630000,
        ]);

        foreach ([
            User::factory()->admin()->create(['is_active' => true]),
            User::factory()->manager()->create(['is_active' => true]),
        ] as $viewer) {
            Auth::forgetGuards();
            $response = $this->actingAs($viewer, 'web')->getJson('/api/vehicles')->assertOk();
            $response
                ->assertJsonPath('data.0.id', $vehicle->id)
                ->assertJsonPath('data.0.purchase_price', 500000)
                ->assertJsonPath('data.0.asking_price', 650000)
                ->assertJsonPath('data.0.floor_price', 600000);
            $this->assertListOmitsUnrelatedSensitiveData($response->json('data.0'));
        }

        Auth::forgetGuards();
        $salesResponse = $this->actingAs(
            User::factory()->sales()->create(['is_active' => true]),
            'web',
        )->getJson('/api/vehicles')->assertOk();
        $salesResponse
            ->assertJsonMissingPath('data.0.purchase_price')
            ->assertJsonPath('data.0.asking_price', 650000)
            ->assertJsonPath('data.0.floor_price', 600000)
            ->assertJsonPath('data.0.sold_price', 630000);
        $this->assertListOmitsUnrelatedSensitiveData($salesResponse->json('data.0'));
    }

    /** @param array<string, mixed> $vehicle */
    private function assertListOmitsUnrelatedSensitiveData(array $vehicle): void
    {
        foreach (['gross_profit', 'summary', 'money_entries', 'cash_account', 'cash_account_id', 'photos'] as $field) {
            $this->assertArrayNotHasKey($field, $vehicle);
        }
    }
}
