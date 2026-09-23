<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicVehicleApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_public_vehicles_list_listed_and_reserved_with_minimal_availability(): void
    {
        Vehicle::factory()->create(['status' => 'listed']);
        Vehicle::factory()->create(['status' => 'reserved']);
        Vehicle::factory()->create(['status' => 'preparing']);

        $response = $this->getJson('/api/public/vehicles');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertSame(['available', 'reserved'], collect($response->json('data'))->pluck('availability')->all());
        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('status', $item);
        }
    }

    public function test_public_vehicles_does_not_list_non_public_statuses(): void
    {
        $preparing = Vehicle::factory()->create(['status' => 'preparing']);
        $sold = Vehicle::factory()->create(['status' => 'sold']);
        $cancelled = Vehicle::factory()->create(['status' => 'cancelled']);

        $response = $this->getJson('/api/public/vehicles');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($preparing->id, $ids);
        $this->assertNotContains($sold->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    public function test_public_vehicle_list_orders_available_before_reserved_before_paginating(): void
    {
        $newerReserved = Vehicle::factory()->create([
            'status' => 'reserved',
            'listing_date' => '2026-09-04',
        ]);
        $olderAvailable = Vehicle::factory()->create([
            'status' => 'listed',
            'listing_date' => '2026-09-01',
        ]);
        $newerAvailable = Vehicle::factory()->create([
            'status' => 'listed',
            'listing_date' => '2026-09-02',
        ]);
        $sameDateLaterAvailable = Vehicle::factory()->create([
            'status' => 'listed',
            'listing_date' => '2026-09-02',
        ]);
        $olderReserved = Vehicle::factory()->create([
            'status' => 'reserved',
            'listing_date' => '2026-09-03',
        ]);

        $firstPage = $this->getJson('/api/public/vehicles?per_page=3&page=1')->assertOk();
        $secondPage = $this->getJson('/api/public/vehicles?per_page=3&page=2')->assertOk();

        $this->assertSame(
            [$sameDateLaterAvailable->id, $newerAvailable->id, $olderAvailable->id],
            collect($firstPage->json('data'))->pluck('id')->all(),
        );
        $this->assertSame([$newerReserved->id, $olderReserved->id], collect($secondPage->json('data'))->pluck('id')->all());
    }

    public function test_public_vehicle_detail_keeps_canonical_photo_urls_for_ssr_callers(): void
    {
        config(['filesystems.disks.public.url' => 'https://api.erp.example.com/storage']);
        Storage::forgetDisk('public');

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $this->makePhoto($vehicle, $admin, 'a', true, 0);
        $this->makePhoto($vehicle, $admin, 'b', false, 1);

        $response = $this->getJson("http://nextjs.internal:3000/api/public/vehicles/{$vehicle->id}");

        $response->assertOk();
        $response->assertJsonCount(2, 'data.photos');
        $response
            ->assertJsonPath('data.cover_photo.is_cover', true)
            ->assertJsonPath(
                'data.cover_photo.url',
                "https://api.erp.example.com/storage/vehicles/{$vehicle->id}/a.webp",
            )
            ->assertJsonPath(
                'data.cover_photo.thumbnail_url',
                "https://api.erp.example.com/storage/vehicles/{$vehicle->id}/a_thumb.webp",
            )
            ->assertJsonPath(
                'data.photos.1.url',
                "https://api.erp.example.com/storage/vehicles/{$vehicle->id}/b.webp",
            );
    }

    public function test_public_vehicle_detail_for_reserved_vehicle_is_available_without_internal_status(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'reserved']);

        $this->getJson("/api/public/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.availability', 'reserved')
            ->assertJsonMissingPath('data.status')
            ->assertJsonMissingPath('data.sales_note')
            ->assertJsonMissingPath('data.purchase_price');
    }

    public function test_reserved_public_response_uses_the_same_sensitive_field_whitelist(): void
    {
        $vehicle = Vehicle::factory()->create([
            'status' => 'reserved',
            'vin' => 'WVWZZZ1JZXW000001',
            'license_plate' => 'ABC-1234',
            'purchase_price' => 500000,
            'floor_price' => 550000,
            'sold_price' => 580000,
            'seller_name' => '內部賣方',
            'seller_phone' => '0912345678',
            'buyer_name' => '內部買方',
            'buyer_phone' => '0987654321',
            'sales_note' => '內部銷售備註',
            'condition_note' => '內部車況備註',
            'lien_note' => '內部貸款備註',
            'notes' => '內部一般備註',
        ]);

        $detail = $this->getJson("/api/public/vehicles/{$vehicle->id}")->assertOk()->json('data');
        $list = collect($this->getJson('/api/public/vehicles')->assertOk()->json('data'))
            ->firstWhere('id', $vehicle->id);

        $forbiddenKeys = [
            'status', 'vin', 'license_plate', 'purchase_price', 'floor_price', 'sold_price',
            'seller_name', 'seller_phone', 'seller_customer_id', 'buyer_name', 'buyer_phone',
            'buyer_customer_id', 'sales_note', 'condition_note', 'lien_note', 'notes',
            'purchase_source_type', 'parking_location', 'money_entries', 'summary',
            'cash_account', 'cash_account_id', 'purchase_agent_id', 'sales_agent_id',
        ];

        foreach ([$detail, $list] as $payload) {
            foreach ($forbiddenKeys as $key) {
                $this->assertArrayNotHasKey($key, $payload);
            }
        }
    }

    public function test_public_vehicle_detail_for_non_public_vehicle_returns_uniform_404(): void
    {
        foreach (['preparing', 'sold', 'cancelled'] as $status) {
            $vehicle = Vehicle::factory()->create(['status' => $status]);

            $this->getJson("/api/public/vehicles/{$vehicle->id}")
                ->assertNotFound()
                ->assertJsonPath('message', 'Vehicle not found');
        }

        $this->getJson('/api/public/vehicles/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Vehicle not found');
    }

    public function test_public_vehicle_detail_returns_public_description_but_list_does_not(): void
    {
        $vehicle = Vehicle::factory()->create([
            'status' => 'listed',
            'public_description' => "一手車，里程透明。\n歡迎預約賞車。",
        ]);

        $this->getJson("/api/public/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.availability', 'available')
            ->assertJsonPath('data.public_description', "一手車，里程透明。\n歡迎預約賞車。");

        $listResponse = $this->getJson('/api/public/vehicles')->assertOk();
        $this->assertArrayNotHasKey('public_description', $listResponse->json('data.0'));
    }

    public function test_public_vehicle_detail_returns_null_public_description(): void
    {
        $vehicle = Vehicle::factory()->create([
            'status' => 'listed',
            'public_description' => null,
        ]);

        $this->getJson("/api/public/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['public_description']])
            ->assertJsonPath('data.public_description', null);
    }

    public function test_public_api_does_not_return_purchase_price(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'listed', 'purchase_price' => 500000]);

        $this->getJson("/api/public/vehicles/{$vehicle->id}")->assertJsonMissingPath('data.purchase_price');

        $listResponse = $this->getJson('/api/public/vehicles');
        $listResponse->assertOk();
        $this->assertArrayNotHasKey('purchase_price', $listResponse->json('data.0'));
    }

    public function test_public_api_does_not_return_floor_price(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'listed', 'floor_price' => 550000]);

        $this->getJson("/api/public/vehicles/{$vehicle->id}")->assertJsonMissingPath('data.floor_price');
    }

    public function test_public_api_does_not_return_sold_price(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'listed', 'sold_price' => 580000]);

        $this->getJson("/api/public/vehicles/{$vehicle->id}")->assertJsonMissingPath('data.sold_price');
    }

    public function test_public_api_does_not_return_customer_buyer_or_seller(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);

        $response = $this->getJson("/api/public/vehicles/{$vehicle->id}");

        $response->assertJsonMissingPath('data.customer');
        $response->assertJsonMissingPath('data.buyer');
        $response->assertJsonMissingPath('data.seller');
    }

    public function test_public_api_does_not_return_money_entries(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);

        $this->getJson("/api/public/vehicles/{$vehicle->id}")->assertJsonMissingPath('data.money_entries');
    }

    public function test_public_api_does_not_return_gross_profit_cost_summary_or_cash_account(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);

        $response = $this->getJson("/api/public/vehicles/{$vehicle->id}");

        $response->assertJsonMissingPath('data.gross_profit');
        $response->assertJsonMissingPath('data.cost');
        $response->assertJsonMissingPath('data.summary');
        $response->assertJsonMissingPath('data.cash_account');
        $response->assertJsonMissingPath('data.cash_account_id');
    }

    public function test_public_api_does_not_return_vin_or_license_plate(): void
    {
        $vehicle = Vehicle::factory()->create([
            'status' => 'listed',
            'vin' => 'WVWZZZ1JZXW000001',
            'license_plate' => 'ABC-1234',
        ]);

        $this->getJson("/api/public/vehicles/{$vehicle->id}")->assertJsonMissingPath('data.vin');
        $this->getJson("/api/public/vehicles/{$vehicle->id}")->assertJsonMissingPath('data.license_plate');

        $listResponse = $this->getJson('/api/public/vehicles');
        $listResponse->assertOk();
        $this->assertArrayNotHasKey('vin', $listResponse->json('data.0'));
        $this->assertArrayNotHasKey('license_plate', $listResponse->json('data.0'));
    }

    public function test_public_api_does_not_return_seller_or_buyer_pii(): void
    {
        $vehicle = Vehicle::factory()->create([
            'status' => 'listed',
            'seller_name' => '王小明',
            'seller_phone' => '0912345678',
            'buyer_name' => '陳小華',
            'buyer_phone' => '0987654321',
        ]);

        $response = $this->getJson("/api/public/vehicles/{$vehicle->id}");

        $response->assertJsonMissingPath('data.seller_name');
        $response->assertJsonMissingPath('data.seller_phone');
        $response->assertJsonMissingPath('data.seller_customer_id');
        $response->assertJsonMissingPath('data.buyer_name');
        $response->assertJsonMissingPath('data.buyer_phone');
        $response->assertJsonMissingPath('data.buyer_customer_id');
    }

    public function test_public_api_does_not_return_internal_notes_or_source_info(): void
    {
        $vehicle = Vehicle::factory()->create([
            'status' => 'listed',
            'notes' => '內部備註：客戶欠款尚未結清',
            'sales_note' => '業務備註：可議價',
            'condition_note' => '車況備註：曾泡水',
            'lien_note' => '貸款備註：尚有貸款未清',
            'purchase_source_type' => 'auction',
        ]);

        $response = $this->getJson("/api/public/vehicles/{$vehicle->id}");

        $response->assertJsonMissingPath('data.notes');
        $response->assertJsonMissingPath('data.sales_note');
        $response->assertJsonMissingPath('data.condition_note');
        $response->assertJsonMissingPath('data.lien_note');
        $response->assertJsonMissingPath('data.purchase_source_type');
        $response->assertJsonMissingPath('data.parking_location');
        $response->assertJsonMissingPath('data.has_registration_document');
        $response->assertJsonMissingPath('data.has_spare_key');
        $response->assertJsonMissingPath('data.is_transfer_completed');
        $response->assertJsonMissingPath('data.is_inspection_completed');
        $response->assertJsonMissingPath('data.is_preparation_completed');
    }

    public function test_public_api_can_be_read_without_authentication(): void
    {
        Vehicle::factory()->create(['status' => 'listed']);

        $listResponse = $this->getJson('/api/public/vehicles');
        $listResponse->assertOk();

        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $detailResponse = $this->getJson("/api/public/vehicles/{$vehicle->id}");
        $detailResponse->assertOk();
    }
}
