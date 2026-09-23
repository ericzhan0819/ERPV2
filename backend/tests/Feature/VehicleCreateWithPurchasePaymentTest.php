<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\UsesCommissionAttributionFixtures;
use Tests\TestCase;

class VehicleCreateWithPurchasePaymentTest extends TestCase
{
    use RefreshDatabase;
    use UsesCommissionAttributionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommissionAttributionFixtures();
    }

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        return parent::postJson($uri, $this->addCommissionAttributionFixtures($uri, $data), $headers, $options);
    }

    public function test_creating_a_vehicle_without_payment_creates_no_money_entry(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'purchase_price' => 500000,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $this->assertSame(0, MoneyEntry::query()->where('vehicle_id', $response->json('data.id'))->count());
    }

    public function test_creating_a_vehicle_with_payment_creates_vehicle_and_approved_expense_entry_together(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'purchase_price' => 500000,
            'seller_name' => '王大明',
            'idempotency_key' => (string) Str::uuid(),
            'initial_purchase_payment' => [
                'amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'entry_date' => '2026-01-01',
                'description' => '先付訂金',
            ],
        ]);

        $response->assertCreated();
        $vehicleId = $response->json('data.id');

        $this->assertDatabaseHas('money_entries', [
            'vehicle_id' => $vehicleId,
            'direction' => 'expense',
            'category' => '購車付款',
            'amount' => 100000,
            'cash_account_id' => $cashAccount->id,
            'source_type' => MoneyEntry::SOURCE_VEHICLE_WORKFLOW,
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
        ]);
    }

    public function test_payment_creation_failure_due_to_inactive_cash_account_rolls_back_the_whole_vehicle(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => false]);
        $idempotencyKey = (string) Str::uuid();

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'idempotency_key' => $idempotencyKey,
            'initial_purchase_payment' => [
                'amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('vehicles', ['idempotency_key' => $idempotencyKey]);
        $this->assertDatabaseMissing('money_entries', ['cash_account_id' => $cashAccount->id]);
    }

    public function test_retry_with_same_idempotency_key_and_same_payload_replays_without_duplicating(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'idempotency_key' => $idempotencyKey,
            'initial_purchase_payment' => [
                'amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'entry_date' => '2026-01-01',
            ],
        ];

        $first = $this->actingAs($user, 'web')->postJson('/api/vehicles', $payload);
        $first->assertCreated();
        $vehicleId = $first->json('data.id');

        $second = $this->actingAs($user, 'web')->postJson('/api/vehicles', $payload);
        $second->assertSuccessful();
        $this->assertSame($vehicleId, $second->json('data.id'));

        $this->assertSame(1, Vehicle::query()->where('idempotency_key', $idempotencyKey)->count());
        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicleId)
            ->where('category', '購車付款')
            ->count());
    }

    public function test_retry_with_same_idempotency_key_but_different_payload_is_rejected(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated();

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'license_plate' => 'ABC-1234',
            'idempotency_key' => $idempotencyKey,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(1, Vehicle::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_retry_with_same_idempotency_key_omitting_a_field_that_actually_differs_is_rejected(): void
    {
        // 重試若省略了某個 optional 欄位（這裡是 color），不能因為「這次沒帶所以不比對」
        // 就被誤判成跟原本已儲存的值相同而靜默 replay 成功；省略掉的欄位必須視為
        // 「未提供 = null」，一旦與原本已儲存的非 null 值不同，就必須被視為不同 payload。
        $user = User::factory()->admin()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'color' => '白色',
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated();

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            // color 被省略，而不是明確傳入相同的 '白色'。
            'idempotency_key' => $idempotencyKey,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(1, Vehicle::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_exact_retry_still_replays_after_the_vehicle_was_legitimately_edited_since_creation(): void
    {
        // 車輛建立後可以合法地被 update 修改（例如補上顏色、里程）。若冪等比對是拿
        // 「車輛目前的即時狀態」而不是「建車當下的快照」去比對，同一把 idempotency_key
        // 的完全相同重試（例如網路逾時造成的重送）會因為車輛已被後續合法編輯過，
        // 誤判成「不同建車內容」而被 422 拒絕，即使 retry payload 與當初建立時一字不差。
        $user = User::factory()->admin()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'color' => '白色',
            'idempotency_key' => $idempotencyKey,
        ];

        $first = $this->actingAs($user, 'web')->postJson('/api/vehicles', $payload);
        $first->assertCreated();
        $vehicleId = $first->json('data.id');

        $this->actingAs($user, 'web')->patchJson("/api/vehicles/{$vehicleId}", [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'color' => '銀色',
            'mileage_km' => 5000,
        ])->assertOk();

        $retry = $this->actingAs($user, 'web')->postJson('/api/vehicles', $payload);

        $retry->assertSuccessful();
        $this->assertSame($vehicleId, $retry->json('data.id'));
        $this->assertSame(1, Vehicle::query()->where('idempotency_key', $idempotencyKey)->count());

        // 重播不應該把已被合法編輯過的車輛欄位改回建車當下的舊值。
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicleId,
            'color' => '銀色',
            'mileage_km' => 5000,
        ]);
    }

    public function test_exact_retry_still_replays_after_the_linked_sellers_customer_record_was_renamed(): void
    {
        // seller_name/seller_phone 在指定 seller_customer_id 時，一律以該客戶「當下」
        // 資料覆寫（見 applySellerCustomerSnapshot）。若冪等比對把這兩個衍生欄位也
        // 納入，客戶在兩次請求之間被改名，就會讓「同樣指定同一位客戶」的完全相同
        // 重試被誤判成不同建車內容而 422。
        $user = User::factory()->admin()->create(['is_active' => true]);
        $customer = Customer::factory()->create(['name' => '原始姓名', 'phone' => '0911111111']);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'seller_customer_id' => $customer->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $first = $this->actingAs($user, 'web')->postJson('/api/vehicles', $payload);
        $first->assertCreated();
        $vehicleId = $first->json('data.id');
        $first->assertJsonPath('data.seller_name', '原始姓名');

        $customer->update(['name' => '改名後的客戶', 'phone' => '0922222222']);

        $retry = $this->actingAs($user, 'web')->postJson('/api/vehicles', $payload);

        $retry->assertSuccessful();
        $this->assertSame($vehicleId, $retry->json('data.id'));
        $this->assertSame(1, Vehicle::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_creating_a_vehicle_without_idempotency_key_fails_validation(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('idempotency_key');
    }

    public function test_idempotency_key_longer_than_the_derived_payment_key_limit_fails_validation(): void
    {
        // 建車同步購車付款時，實際寫入 money_entries.idempotency_key（欄位長度 100）
        // 的鍵是 "{idempotency_key}:initial-payment"（衍生後綴 16 字元），因此
        // idempotency_key 本身上限必須是 84，否則衍生鍵會超出欄位長度，讓原本合法
        // 的建車請求在寫入付款時因資料庫例外而整筆回滾。
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'idempotency_key' => str_repeat('a', 85),
            'initial_purchase_payment' => [
                'amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('idempotency_key');
    }

    public function test_idempotency_key_at_the_derived_payment_key_limit_succeeds(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = str_repeat('a', 84);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'idempotency_key' => $idempotencyKey,
            'initial_purchase_payment' => [
                'amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('money_entries', [
            'idempotency_key' => $idempotencyKey.':initial-payment',
        ]);
    }

    public function test_idempotency_key_longer_than_84_chars_still_succeeds_without_payment(): void
    {
        // max:84 只在「這次請求真的會建立同步付款」時才需要收緊，因為那才會衍生出
        // 受 money_entries.idempotency_key 欄位長度限制的鍵。純建車（無
        // initial_purchase_payment）不會產生這把衍生鍵，不應該被連帶波及，
        // 沿用既有其他端點（reserve/final-payment）的 max:100 即可。
        $user = User::factory()->admin()->create(['is_active' => true]);
        $idempotencyKey = str_repeat('a', 100);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'idempotency_key' => $idempotencyKey,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('vehicles', ['idempotency_key' => $idempotencyKey]);
    }

    public function test_retry_with_full_datetime_entry_date_replays_against_the_date_only_stored_value(): void
    {
        // FormRequest 的 date 規則接受完整 datetime 字串。若正規化沒有把它收斂成
        // 純日期，重試比對會拿 money_entries.entry_date 存回的純日期字串對「原始
        // datetime 字串」，兩者永遠對不上，導致完全相同的重試被誤判成不同 payload
        // 而 422，即使第一次請求已經成功。
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'idempotency_key' => $idempotencyKey,
            'initial_purchase_payment' => [
                'amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'entry_date' => '2026-01-01 10:00:00',
            ],
        ];

        $first = $this->actingAs($user, 'web')->postJson('/api/vehicles', $payload);
        $first->assertCreated();
        $vehicleId = $first->json('data.id');

        $storedEntry = MoneyEntry::query()->where('vehicle_id', $vehicleId)->where('category', '購車付款')->firstOrFail();
        $this->assertSame('2026-01-01', $storedEntry->entry_date?->toDateString());

        $retry = $this->actingAs($user, 'web')->postJson('/api/vehicles', $payload);

        $retry->assertSuccessful();
        $this->assertSame($vehicleId, $retry->json('data.id'));
        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicleId)
            ->where('category', '購車付款')
            ->count());
    }

    public function test_sales_cannot_create_vehicle_with_initial_purchase_payment(): void
    {
        // sales 已被 VehiclePolicy::create 完全擋在建車功能之外（見 RoleAccessTest），
        // 因此連同 initial_purchase_payment 一起送出時，仍會在到達 FormRequest 驗證前
        // 就被 403 擋下，而非放行到建車流程再檢查。
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $response = $this->actingAs($sales, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'idempotency_key' => (string) Str::uuid(),
            'initial_purchase_payment' => [
                'amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('vehicles', ['brand' => 'Toyota', 'model' => 'Camry']);
    }

    public function test_create_vehicle_retry_after_duplicate_key_error_from_same_connection_insert(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $data = [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'purchase_price' => 500000,
            'purchase_agent_id' => $this->defaultCommissionAgent->id,
        ];

        $vehicle = app(VehicleService::class)->createVehicle(array_merge($data, [
            'idempotency_key' => $idempotencyKey,
            'initial_purchase_payment' => [
                'amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'entry_date' => '2026-01-01',
            ],
        ]), $user->id);

        $replayed = app(VehicleService::class)->createVehicle(array_merge($data, [
            'idempotency_key' => $idempotencyKey,
            'initial_purchase_payment' => [
                'amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'entry_date' => '2026-01-01',
            ],
        ]), $user->id);

        $this->assertSame($vehicle->id, $replayed->id);
        $this->assertSame(1, MoneyEntry::query()->where('vehicle_id', $vehicle->id)->count());
    }
}
