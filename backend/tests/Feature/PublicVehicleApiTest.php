<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_public_vehicles_only_lists_listed_vehicles(): void
    {
        Vehicle::factory()->create(['status' => 'listed']);
        Vehicle::factory()->create(['status' => 'listed']);
        Vehicle::factory()->create(['status' => 'preparing']);

        $response = $this->getJson('/api/public/vehicles');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_public_vehicles_does_not_list_non_listed_statuses(): void
    {
        $preparing = Vehicle::factory()->create(['status' => 'preparing']);
        $reserved = Vehicle::factory()->create(['status' => 'reserved']);
        $sold = Vehicle::factory()->create(['status' => 'sold']);
        $cancelled = Vehicle::factory()->create(['status' => 'cancelled']);

        $response = $this->getJson('/api/public/vehicles');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($preparing->id, $ids);
        $this->assertNotContains($reserved->id, $ids);
        $this->assertNotContains($sold->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    public function test_public_vehicle_detail_returns_photos_and_cover_photo(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $this->makePhoto($vehicle, $admin, 'a', true, 0);
        $this->makePhoto($vehicle, $admin, 'b', false, 1);

        $response = $this->getJson("/api/public/vehicles/{$vehicle->id}");

        $response->assertOk();
        $response->assertJsonCount(2, 'data.photos');
        $response->assertJsonPath('data.cover_photo.is_cover', true);
    }

    public function test_public_vehicle_detail_for_non_listed_vehicle_returns_404(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        $response = $this->getJson("/api/public/vehicles/{$vehicle->id}");

        $response->assertStatus(404);
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
