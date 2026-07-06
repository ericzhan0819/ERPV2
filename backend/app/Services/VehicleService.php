<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleService
{
    /**
     * 建車冪等 payload 比對必須涵蓋的完整欄位集合。使用固定清單而非「這次請求帶了
     * 哪些欄位就比對哪些」，是因為重試若省略了某個 optional 欄位（無論是刻意送出
     * 精簡過的 payload，或單純的 client bug），只依賴「有帶的欄位」比對會直接跳過
     * 該欄位的檢查，讓內容其實不同的請求被誤判成「相同 payload」而靜默 replay 成功。
     */
    private const COMPARABLE_VEHICLE_FIELDS = [
        'brand', 'model', 'year', 'license_plate', 'vin', 'mileage_km', 'color',
        'displacement', 'transmission', 'fuel_type', 'parking_location',
        'has_registration_document', 'has_spare_key', 'is_transfer_completed',
        'is_inspection_completed', 'is_preparation_completed',
        'lien_note', 'condition_note', 'purchase_date', 'purchase_source_type',
        'seller_name', 'seller_phone', 'seller_customer_id',
        'purchase_price', 'asking_price', 'floor_price', 'sales_note', 'notes',
    ];

    private const BOOLEAN_VEHICLE_FIELDS = [
        'has_registration_document',
        'has_spare_key',
        'is_transfer_completed',
        'is_inspection_completed',
        'is_preparation_completed',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listVehicles(array $filters): LengthAwarePaginator
    {
        $query = Vehicle::query();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('stock_no', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('license_plate', 'like', "%{$search}%")
                    ->orWhere('vin', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createVehicle(array $data, int $userId): Vehicle
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        $effectiveData = $this->normalizeCreateVehicleData($data);

        try {
            return DB::transaction(fn () => $this->createVehicleInsideTransaction(
                $idempotencyKey,
                $effectiveData,
                $userId
            ));
        } catch (QueryException $e) {
            if (! $this->isIdempotencyKeyUniqueViolation($e)) {
                throw $e;
            }

            return $this->replayRacedVehicleCreationAfterRollback($e, $idempotencyKey, $effectiveData);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{vehicle: array<string, mixed>, comparable: array<string, mixed>, payment: array{amount: int, cash_account_id: int, entry_date: string, description: string|null, entry_date_was_supplied: bool}|null}
     */
    private function normalizeCreateVehicleData(array $data): array
    {
        $vehicleData = $data;
        unset($vehicleData['idempotency_key'], $vehicleData['initial_purchase_payment']);

        $vehicleData = $this->applySellerCustomerSnapshot($vehicleData);
        $vehicleData = $this->normalizeIntakeCheckFields($vehicleData);
        $vehicleData = $this->castVehicleFieldsForComparison($vehicleData);

        return [
            'vehicle' => $vehicleData,
            'comparable' => $this->buildComparableVehicleFields($vehicleData),
            'payment' => $this->normalizeInitialPurchasePaymentData($data['initial_purchase_payment'] ?? null),
        ];
    }

    /**
     * 把「這次請求帶了哪些欄位」正規化成完整、固定欄位集合的規範化表示：布林欄位缺席
     * 時比照 Vehicle model 的 mass-assignment 預設值視為 false，其餘欄位缺席時視為
     * null。這樣不論兩次請求各自省略了哪些 optional 欄位，只要「實際會落地的值」
     * 相同就能正確比對，而不是「兩次都剛好帶了同一組欄位」才能比對。
     *
     * @param  array<string, mixed>  $vehicleData
     * @return array<string, mixed>
     */
    private function buildComparableVehicleFields(array $vehicleData): array
    {
        $comparable = [];
        // 一旦指定 seller_customer_id，seller_name/seller_phone 就是每次都會被該客戶
        // 「當下」資料覆寫的衍生快照（見 applySellerCustomerSnapshot），不是這次請求
        // 穩定的身份特徵——客戶事後改名/改電話會讓這兩個值隨時間變動。若把它們納入
        // 冪等比對，同一把 idempotency_key、同樣指定同一位客戶的完全相同重試，會因為
        // 客戶資料在兩次請求之間被改過而被誤判成「不同建車內容」。冪等比對只需認定
        // seller_customer_id 本身（已在下方迴圈中一併比對），略過這兩個衍生欄位。
        $hasSellerCustomerLink = ! empty($vehicleData['seller_customer_id']);

        foreach (self::COMPARABLE_VEHICLE_FIELDS as $field) {
            if ($hasSellerCustomerLink && in_array($field, ['seller_name', 'seller_phone'], true)) {
                continue;
            }

            if (in_array($field, self::BOOLEAN_VEHICLE_FIELDS, true)) {
                $comparable[$field] = array_key_exists($field, $vehicleData) ? (bool) $vehicleData[$field] : false;
                continue;
            }

            $comparable[$field] = $vehicleData[$field] ?? null;
        }

        return $comparable;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function castVehicleFieldsForComparison(array $data): array
    {
        foreach (['year', 'mileage_km', 'purchase_price', 'asking_price', 'floor_price', 'seller_customer_id'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = (int) $data[$field];
            }
        }

        foreach ([
            'has_registration_document',
            'has_spare_key',
            'is_transfer_completed',
            'is_inspection_completed',
            'is_preparation_completed',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (bool) $data[$field];
            }
        }

        foreach (['purchase_date', 'listing_date'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = \Illuminate\Support\Carbon::parse($data[$field])->toDateString();
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>|null  $paymentInput
     * @return array{amount: int, cash_account_id: int, entry_date: string, description: string|null, entry_date_was_supplied: bool}|null
     */
    private function normalizeInitialPurchasePaymentData(?array $paymentInput): ?array
    {
        if (empty($paymentInput)) {
            return null;
        }

        $entryDateWasSupplied = ! empty($paymentInput['entry_date']);

        return [
            'amount' => (int) $paymentInput['amount'],
            'cash_account_id' => (int) $paymentInput['cash_account_id'],
            'entry_date' => $entryDateWasSupplied ? $paymentInput['entry_date'] : now()->toDateString(),
            'description' => $paymentInput['description'] ?? null,
            'entry_date_was_supplied' => $entryDateWasSupplied,
        ];
    }

    /**
     * @param  array{vehicle: array<string, mixed>, comparable: array<string, mixed>, payment: array{amount: int, cash_account_id: int, entry_date: string, description: string|null, entry_date_was_supplied: bool}|null}  $effectiveData
     */
    private function createVehicleInsideTransaction(string $idempotencyKey, array $effectiveData, int $userId): Vehicle
    {
        $existingVehicle = Vehicle::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingVehicle) {
            return $this->replayOrRejectVehicleCreation($existingVehicle, $effectiveData);
        }

        $vehicle = new Vehicle($effectiveData['vehicle']);
        $vehicle->stock_no = $this->generateStockNo();
        $vehicle->status = 'preparing';
        $vehicle->idempotency_key = $idempotencyKey;
        $vehicle->idempotency_payload = json_encode($effectiveData['comparable'], JSON_THROW_ON_ERROR);
        $vehicle->created_by = $userId;
        $vehicle->updated_by = $userId;
        $vehicle->save();

        if ($effectiveData['payment'] !== null) {
            $this->assertCashAccountActive($effectiveData['payment']['cash_account_id']);

            $entry = new MoneyEntry([
                'vehicle_id' => $vehicle->id,
                'cash_account_id' => $effectiveData['payment']['cash_account_id'],
                'entry_date' => $effectiveData['payment']['entry_date'],
                'direction' => 'expense',
                'category' => '購車付款',
                'amount' => $effectiveData['payment']['amount'],
                'counterparty_name' => $vehicle->seller_name,
                'description' => $effectiveData['payment']['description'],
                'idempotency_key' => $idempotencyKey.':initial-payment',
                'source_type' => MoneyEntry::SOURCE_VEHICLE_WORKFLOW,
            ]);
            $entry->created_by = $userId;
            $entry->updated_by = $userId;
            // 車輛流程收支不進審核佇列，避免建車流程被卡住。
            $entry->approval_status = MoneyEntry::APPROVAL_APPROVED;

            // Let a duplicate-key QueryException escape so DB::transaction rolls back
            // the vehicle insert too; it is caught and retried by the caller.
            $entry->save();
        }

        return $vehicle;
    }

    /**
     * @param  array{vehicle: array<string, mixed>, comparable: array<string, mixed>, payment: array{amount: int, cash_account_id: int, entry_date: string, description: string|null, entry_date_was_supplied: bool}|null}  $effectiveData
     */
    private function replayRacedVehicleCreationAfterRollback(QueryException $original, string $idempotencyKey, array $effectiveData): Vehicle
    {
        return DB::transaction(function () use ($original, $idempotencyKey, $effectiveData) {
            // Same MySQL REPEATABLE READ stale-snapshot concern as reserve/final-payment:
            // after a rollback, re-read the idempotency_key in a fresh transaction/locking read.
            $racedVehicle = Vehicle::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $racedVehicle) {
                throw $original;
            }

            return $this->replayOrRejectVehicleCreation($racedVehicle, $effectiveData);
        });
    }

    /**
     * @param  array{vehicle: array<string, mixed>, comparable: array<string, mixed>, payment: array{amount: int, cash_account_id: int, entry_date: string, description: string|null, entry_date_was_supplied: bool}|null}  $effectiveData
     */
    private function replayOrRejectVehicleCreation(Vehicle $existingVehicle, array $effectiveData): Vehicle
    {
        if (! $this->isSameVehicleCreationRequest($existingVehicle, $effectiveData)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['此冪等鍵已被不同建車內容使用，請重新整理後再試'],
            ]);
        }

        return $existingVehicle;
    }

    /**
     * @param  array{vehicle: array<string, mixed>, comparable: array<string, mixed>, payment: array{amount: int, cash_account_id: int, entry_date: string, description: string|null, entry_date_was_supplied: bool}|null}  $effectiveData
     */
    private function isSameVehicleCreationRequest(Vehicle $existingVehicle, array $effectiveData): bool
    {
        // 比對對象是「建車當下儲存的快照」，而不是車輛目前的即時狀態：車輛在建立後
        // 可以合法地被 update/list/reserve 等流程繼續修改，若拿目前狀態比對，同一把
        // idempotency_key 的「完全相同」重試會因為車輛後續被正常編輯過而被誤判成
        // 「不同建車內容」而 422，即使這次重試的 payload 其實跟當初建立時一字不差。
        $storedComparable = json_decode((string) $existingVehicle->idempotency_payload, true);

        if (! is_array($storedComparable) || $storedComparable !== $effectiveData['comparable']) {
            return false;
        }

        return $this->isSameInitialPurchasePaymentRequest($existingVehicle, $effectiveData['payment']);
    }

    /**
     * @param  array{amount: int, cash_account_id: int, entry_date: string, description: string|null, entry_date_was_supplied: bool}|null  $payment
     */
    private function isSameInitialPurchasePaymentRequest(Vehicle $existingVehicle, ?array $payment): bool
    {
        $existingEntry = MoneyEntry::query()
            ->where('vehicle_id', $existingVehicle->id)
            ->where('idempotency_key', $existingVehicle->idempotency_key.':initial-payment')
            ->first();

        if ($payment === null) {
            return $existingEntry === null;
        }

        if (! $existingEntry) {
            return false;
        }

        if ((int) $existingEntry->amount !== $payment['amount']) {
            return false;
        }

        if ((int) $existingEntry->cash_account_id !== $payment['cash_account_id']) {
            return false;
        }

        if ($existingEntry->description !== $payment['description']) {
            return false;
        }

        if ($payment['entry_date_was_supplied'] && $existingEntry->entry_date?->toDateString() !== $payment['entry_date']) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateVehicle(Vehicle $vehicle, array $data, int $userId): Vehicle
    {
        $data = $this->applySellerCustomerSnapshot($data);
        $data = $this->normalizeIntakeCheckFields($data);

        return DB::transaction(function () use ($vehicle, $data, $userId) {
            // 重新鎖定並讀取目前 DB 狀態，而不是沿用 route-bound 的 $vehicle：
            // 若另一個請求（例如 listVehicle()）在這個請求載入 $vehicle 之後、
            // 送出更新之前完成了 preparing → listed 並把 is_preparation_completed
            // 設為 true，這裡若仍檢查記憶體中舊的 status/欄位值，就會誤判成
            // 「還在 preparing」而放行 false，重新造出 listed + 整備未完成的矛盾狀態。
            $lockedVehicle = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->firstOrFail();

            // seller_customer_id may simply be absent from this request (as opposed to
            // explicitly cleared to null) while the vehicle already has a link. That
            // link is not being touched, so the snapshot must not move either way:
            // - it must NOT be re-derived from the customer's *current* data, since
            //   seller_name/phone are a historical snapshot captured at link time —
            //   letting a later, unrelated vehicle edit (e.g. just updating mileage)
            //   silently pull in whatever the customer has been renamed to since would
            //   retroactively rewrite history.
            // - it must NOT take whatever free-text seller_name/phone happened to be
            //   in this request either, since that could silently diverge from the
            //   customer the vehicle is still linked to.
            // Either way the existing stored values win, so any submitted values for
            // these two fields are dropped before fill() when the link is untouched.
            if (! array_key_exists('seller_customer_id', $data) && $lockedVehicle->seller_customer_id) {
                unset($data['seller_name'], $data['seller_phone']);
            }

            // listVehicle()（整備完成並上架）會把 is_preparation_completed 設為 true，
            // 代表「已上架」本身即宣告整備完成。一般更新 API 不應該讓這個已宣告的事實
            // 被回改成 false／null，否則車輛在上架中/保留中/已售出狀態下又會重新出現
            // 「整備未完成」，與上架動作的語意矛盾。未觸及此欄位或欲設為 true 則不受影響。
            // 這裡必須明確拒絕（422）而不是靜默丟棄該欄位再回傳 200：呼叫端才能分辨
            // 這次請求的其他欄位是否真的有被儲存，而不是誤以為整份請求都成功了。
            if (array_key_exists('is_preparation_completed', $data)
                && ! $data['is_preparation_completed']
                && in_array($lockedVehicle->status, ['listed', 'reserved', 'sold'], true)) {
                throw ValidationException::withMessages([
                    'is_preparation_completed' => ['車輛已上架，無法將整備完成狀態改回未完成'],
                ]);
            }

            $lockedVehicle->fill($data);
            $lockedVehicle->updated_by = $userId;
            $lockedVehicle->save();

            return $lockedVehicle;
        });
    }

    /**
     * 賣方客戶連結是權威來源：一旦指定 seller_customer_id，seller_name/seller_phone
     * 一律以該客戶目前資料覆寫，避免使用者輸入與所選客戶不一致而讓車輛紀錄無法可靠
     * 對應到實際客戶。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applySellerCustomerSnapshot(array $data): array
    {
        if (empty($data['seller_customer_id'])) {
            return $data;
        }

        return array_merge($data, $this->resolveSellerCustomerSnapshot((int) $data['seller_customer_id']));
    }

    /**
     * 入庫檢核欄位在資料庫是 NOT NULL boolean（default false）。FormRequest 允許
     * 顯式傳入 null（視為「未勾選」），若原樣寫入會觸發 DB NOT NULL 例外，因此
     * 在進 mass assignment 前先把顯式 null 正規化為 false；完全未帶欄位則維持
     * 不動，讓 create 走 model 預設值、update 保留原值。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeIntakeCheckFields(array $data): array
    {
        foreach ([
            'has_registration_document',
            'has_spare_key',
            'is_transfer_completed',
            'is_inspection_completed',
            'is_preparation_completed',
        ] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = false;
            }
        }

        return $data;
    }

    /**
     * @return array{seller_name: string, seller_phone: string|null}
     */
    private function resolveSellerCustomerSnapshot(int $customerId): array
    {
        $customer = Customer::query()->find($customerId);

        if (! $customer) {
            throw ValidationException::withMessages([
                'seller_customer_id' => ['指定的賣方客戶不存在'],
            ]);
        }

        return ['seller_name' => $customer->name, 'seller_phone' => $customer->phone];
    }

    public function deleteVehicle(Vehicle $vehicle): void
    {
        DB::transaction(function () use ($vehicle) {
            $lockedVehicle = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedVehicle->status !== 'preparing') {
                throw ValidationException::withMessages([
                    'status' => ['只有整備中的新建車輛可以刪除'],
                ]);
            }

            $hasMoneyEntries = MoneyEntry::query()
                ->where('vehicle_id', $lockedVehicle->id)
                ->exists();

            if ($hasMoneyEntries) {
                throw ValidationException::withMessages([
                    'status' => ['已有收支紀錄的車輛不得刪除，請改用取消/退車流程'],
                ]);
            }

            $lockedVehicle->delete();
        });
    }

    /**
     * @return array{income_total: int, expense_total: int, gross_profit: int}
     */
    public function financialSummary(Vehicle $vehicle): array
    {
        $incomeTotal = (int) MoneyEntry::query()
            ->approved()
            ->where('vehicle_id', $vehicle->id)
            ->where('direction', 'income')
            ->sum('amount');

        $expenseTotal = (int) MoneyEntry::query()
            ->approved()
            ->where('vehicle_id', $vehicle->id)
            ->where('direction', 'expense')
            ->sum('amount');

        return [
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'gross_profit' => $incomeTotal - $expenseTotal,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function listVehicle(Vehicle $vehicle, array $data, int $userId): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $userId) {
            $lockedVehicle = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertStatus($lockedVehicle, 'preparing', '只有整備中的車輛可以上架');

            $lockedVehicle->fill([
                'asking_price' => $data['asking_price'],
                'floor_price' => $data['floor_price'] ?? null,
                'listing_date' => $data['listing_date'] ?? now()->toDateString(),
                'sales_note' => $data['sales_note'] ?? null,
                // 「整備完成並上架」這個動作本身就是在宣告整備已完成，若不同步這裡，
                // 車輛會在已上架/已售出狀態下仍顯示「整備未完成」，與操作語意矛盾。
                'is_preparation_completed' => true,
            ]);
            $lockedVehicle->status = 'listed';
            $lockedVehicle->updated_by = $userId;
            $lockedVehicle->save();

            return $lockedVehicle;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reserveVehicle(Vehicle $vehicle, array $data, int $userId): Vehicle
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        $effectiveData = $this->applyBuyerCustomerSnapshot($this->normalizeReserveData($data));

        try {
            return DB::transaction(fn () => $this->createReservationInsideTransaction(
                $vehicle,
                $idempotencyKey,
                $effectiveData,
                $userId
            ));
        } catch (QueryException $e) {
            if (! $this->isIdempotencyKeyUniqueViolation($e)) {
                throw $e;
            }

            return $this->replayRacedReservationAfterRollback($e, $vehicle->id, $idempotencyKey, $effectiveData);
        }
    }

    /**
     * @param  array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     */
    private function createReservationInsideTransaction(Vehicle $vehicle, string $idempotencyKey, array $effectiveData, int $userId): Vehicle
    {
        $existingEntry = MoneyEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingEntry) {
            return $this->replayOrRejectReservation($existingEntry, $vehicle->id, $effectiveData);
        }

        $lockedVehicle = Vehicle::query()
            ->whereKey($vehicle->id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->assertStatus($lockedVehicle, 'listed', '只有上架中的車輛可以收訂金並保留');
        $this->assertCashAccountActive($effectiveData['cash_account_id']);

        $lockedVehicle->fill([
            'buyer_name' => $effectiveData['buyer_name'],
            'buyer_phone' => $effectiveData['buyer_phone'],
            'buyer_customer_id' => $effectiveData['buyer_customer_id'],
            'sold_price' => $effectiveData['sold_price'],
        ]);
        $lockedVehicle->status = 'reserved';
        $lockedVehicle->reserved_at = now();
        $lockedVehicle->updated_by = $userId;
        $lockedVehicle->save();

        $entry = new MoneyEntry([
            'vehicle_id' => $lockedVehicle->id,
            'cash_account_id' => $effectiveData['cash_account_id'],
            'entry_date' => $effectiveData['entry_date'],
            'direction' => 'income',
            'category' => '訂金收入',
            'amount' => $effectiveData['deposit_amount'],
            'counterparty_name' => $effectiveData['buyer_name'],
            'description' => $effectiveData['description'],
            'idempotency_key' => $idempotencyKey,
            'source_type' => MoneyEntry::SOURCE_VEHICLE_WORKFLOW,
        ]);
        $entry->created_by = $userId;
        $entry->updated_by = $userId;
        // 車輛流程收支不進審核佇列，避免車輛狀態流程被卡住。
        $entry->approval_status = MoneyEntry::APPROVAL_APPROVED;

        // Let a duplicate-key QueryException escape so DB::transaction rolls back;
        // it is caught and retried against a fresh transaction/snapshot by the caller.
        $entry->save();

        return $lockedVehicle;
    }

    /**
     * @param  array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     */
    private function replayRacedReservationAfterRollback(QueryException $original, int $vehicleId, string $idempotencyKey, array $effectiveData): Vehicle
    {
        return DB::transaction(function () use ($original, $vehicleId, $idempotencyKey, $effectiveData) {
            // Same MySQL REPEATABLE READ stale-snapshot concern as final payment: after a
            // rollback, re-read the idempotency_key in a fresh transaction/locking read.
            $racedEntry = MoneyEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $racedEntry) {
                throw $original;
            }

            return $this->replayOrRejectReservation($racedEntry, $vehicleId, $effectiveData);
        });
    }

    /**
     * @param  array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     */
    private function replayOrRejectReservation(MoneyEntry $entry, int $vehicleId, array $effectiveData): Vehicle
    {
        if (! $this->isSameReservationRequest($entry, $vehicleId, $effectiveData)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['此冪等鍵已被不同保留/訂金內容使用，請重新整理後再試'],
            ]);
        }

        return Vehicle::query()->whereKey($entry->vehicle_id)->firstOrFail();
    }

    /**
     * @param  array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     */
    private function isSameReservationRequest(MoneyEntry $entry, int $vehicleId, array $effectiveData): bool
    {
        if ((int) $entry->vehicle_id !== $vehicleId) {
            return false;
        }

        if ($entry->direction !== 'income' || $entry->category !== '訂金收入') {
            return false;
        }

        if ((int) $entry->amount !== $effectiveData['deposit_amount']) {
            return false;
        }

        if ((int) $entry->cash_account_id !== $effectiveData['cash_account_id']) {
            return false;
        }

        if ($entry->counterparty_name !== $effectiveData['buyer_name']) {
            return false;
        }

        if ($entry->description !== $effectiveData['description']) {
            return false;
        }

        if ($effectiveData['entry_date_was_supplied'] && $entry->entry_date?->toDateString() !== $effectiveData['entry_date']) {
            return false;
        }

        // Deposit entry 本身沒有記錄 sold_price/buyer_phone，但 reserve request 會把
        // 這些欄位寫進 vehicle。retry 必須確認 vehicle 上目前的值仍與 request 一致，
        // 否則會 silently replay 成功但漏掉 sold_price/buyer_phone 已被改過的事實。
        $vehicle = Vehicle::query()->whereKey($entry->vehicle_id)->first();

        if (! $vehicle) {
            return false;
        }

        if ($vehicle->buyer_name !== $effectiveData['buyer_name']) {
            return false;
        }

        if ($vehicle->buyer_phone !== $effectiveData['buyer_phone']) {
            return false;
        }

        if ((int) $vehicle->buyer_customer_id !== (int) $effectiveData['buyer_customer_id']) {
            return false;
        }

        if ((int) $vehicle->sold_price !== $effectiveData['sold_price']) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}
     */
    private function normalizeReserveData(array $data): array
    {
        $entryDateWasSupplied = ! empty($data['entry_date']);

        return [
            'buyer_name' => $data['buyer_name'],
            'buyer_phone' => $data['buyer_phone'] ?? null,
            'buyer_customer_id' => isset($data['buyer_customer_id']) ? (int) $data['buyer_customer_id'] : null,
            'sold_price' => (int) $data['sold_price'],
            'deposit_amount' => (int) $data['deposit_amount'],
            'cash_account_id' => (int) $data['cash_account_id'],
            'description' => $data['description'] ?? null,
            'entry_date' => $entryDateWasSupplied ? $data['entry_date'] : now()->toDateString(),
            'entry_date_was_supplied' => $entryDateWasSupplied,
        ];
    }

    /**
     * 買方客戶連結是權威來源：一旦指定 buyer_customer_id，buyer_name/buyer_phone 一律
     * 以該客戶目前資料覆寫，避免使用者輸入與所選客戶不一致。套用在 normalize 之後、
     * 進入 transaction 之前，讓建立與 replay 比對看到的都是同一份已解析資料。
     *
     * @param  array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     * @return array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}
     */
    private function applyBuyerCustomerSnapshot(array $effectiveData): array
    {
        if ($effectiveData['buyer_customer_id'] === null) {
            return $effectiveData;
        }

        $customer = Customer::query()->find($effectiveData['buyer_customer_id']);

        if (! $customer) {
            throw ValidationException::withMessages([
                'buyer_customer_id' => ['指定的買方客戶不存在'],
            ]);
        }

        $effectiveData['buyer_name'] = $customer->name;
        $effectiveData['buyer_phone'] = $customer->phone;

        return $effectiveData;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{vehicle: Vehicle, warning: string|null}
     */
    public function recordFinalPayment(Vehicle $vehicle, array $data, int $userId): array
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        $effectiveData = $this->normalizeFinalPaymentData($data);

        try {
            return DB::transaction(fn () => $this->createFinalPaymentInsideTransaction(
                $vehicle,
                $idempotencyKey,
                $effectiveData,
                $userId
            ));
        } catch (QueryException $e) {
            if (! $this->isIdempotencyKeyUniqueViolation($e)) {
                throw $e;
            }

            return $this->replayRacedFinalPaymentAfterRollback($e, $vehicle->id, $idempotencyKey, $effectiveData);
        }
    }

    /**
     * @param  array{amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     * @return array{vehicle: Vehicle, warning: string|null}
     */
    private function createFinalPaymentInsideTransaction(Vehicle $vehicle, string $idempotencyKey, array $effectiveData, int $userId): array
    {
        $existingEntry = MoneyEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingEntry) {
            return $this->replayOrRejectFinalPayment($existingEntry, $vehicle->id, $effectiveData);
        }

        $lockedVehicle = Vehicle::query()
            ->whereKey($vehicle->id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->assertStatus($lockedVehicle, 'reserved', '只有保留中的車輛可以收尾款');
        $this->assertCashAccountActive($effectiveData['cash_account_id']);

        $entry = new MoneyEntry([
            'vehicle_id' => $lockedVehicle->id,
            'cash_account_id' => $effectiveData['cash_account_id'],
            'entry_date' => $effectiveData['entry_date'],
            'direction' => 'income',
            'category' => '尾款收入',
            'amount' => $effectiveData['amount'],
            'counterparty_name' => $lockedVehicle->buyer_name,
            'description' => $effectiveData['description'],
            'idempotency_key' => $idempotencyKey,
            'source_type' => MoneyEntry::SOURCE_VEHICLE_WORKFLOW,
        ]);
        $entry->created_by = $userId;
        $entry->updated_by = $userId;
        // 車輛流程收支不進審核佇列，避免車輛狀態流程被卡住。
        $entry->approval_status = MoneyEntry::APPROVAL_APPROVED;

        // Let a duplicate-key QueryException escape so DB::transaction rolls back;
        // it is caught and retried against a fresh transaction/snapshot by the caller.
        $entry->save();

        return [
            'vehicle' => $lockedVehicle,
            'warning' => $this->buildFinalPaymentWarning($lockedVehicle),
        ];
    }

    /**
     * @param  array{amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     * @return array{vehicle: Vehicle, warning: string|null}
     */
    private function replayRacedFinalPaymentAfterRollback(QueryException $original, int $vehicleId, string $idempotencyKey, array $effectiveData): array
    {
        return DB::transaction(function () use ($original, $vehicleId, $idempotencyKey, $effectiveData) {
            // Fresh transaction after rollback: under MySQL REPEATABLE READ, re-reading the
            // idempotency_key inside the same (now rolled-back) transaction could still see
            // the pre-race snapshot and miss the winner's row. Start a new transaction and
            // take a locking read so we observe the committed winner.
            $racedEntry = MoneyEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $racedEntry) {
                throw $original;
            }

            return $this->replayOrRejectFinalPayment($racedEntry, $vehicleId, $effectiveData);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function closeSale(Vehicle $vehicle, array $data, int $userId): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $userId) {
            $lockedVehicle = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertStatus($lockedVehicle, 'reserved', '只有保留中的車輛可以成交結案');

            if (! $lockedVehicle->sold_price || ! $lockedVehicle->buyer_name) {
                throw ValidationException::withMessages([
                    'sold_price' => ['成交結案前必須已有成交價與買方資料'],
                ]);
            }

            $hasIncome = MoneyEntry::query()
                ->approved()
                ->where('vehicle_id', $lockedVehicle->id)
                ->where('direction', 'income')
                ->exists();

            if (! $hasIncome) {
                throw ValidationException::withMessages([
                    'sold_price' => ['成交結案前必須至少已有一筆訂金或尾款收入'],
                ]);
            }

            $lockedVehicle->status = 'sold';
            $lockedVehicle->sold_at = $data['sold_at'] ?? now();
            $lockedVehicle->updated_by = $userId;
            $lockedVehicle->save();

            return $lockedVehicle;
        });
    }

    private function assertStatus(Vehicle $vehicle, string $expected, string $message): void
    {
        if ($vehicle->status !== $expected) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    private function assertCashAccountActive(int $cashAccountId): void
    {
        $isActive = CashAccount::query()
            ->whereKey($cashAccountId)
            ->lockForUpdate()
            ->value('is_active');

        if (! $isActive) {
            throw ValidationException::withMessages([
                'cash_account_id' => ['停用帳戶不得新增收支'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}
     */
    private function normalizeFinalPaymentData(array $data): array
    {
        $entryDateWasSupplied = ! empty($data['entry_date']);

        return [
            'amount' => (int) $data['amount'],
            'cash_account_id' => (int) $data['cash_account_id'],
            'description' => $data['description'] ?? null,
            // Only used to populate a brand-new entry's date; retries that omit entry_date
            // are not compared against this value (see entry_date_was_supplied below).
            'entry_date' => $entryDateWasSupplied ? $data['entry_date'] : now()->toDateString(),
            'entry_date_was_supplied' => $entryDateWasSupplied,
        ];
    }

    /**
     * @param  array{amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     * @return array{vehicle: Vehicle, warning: string|null}
     */
    private function replayOrRejectFinalPayment(MoneyEntry $entry, int $vehicleId, array $effectiveData): array
    {
        if (! $this->isSameFinalPaymentRequest($entry, $vehicleId, $effectiveData)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['此冪等鍵已被不同尾款付款內容使用，請重新整理後再試'],
            ]);
        }

        $replayVehicle = Vehicle::query()->whereKey($entry->vehicle_id)->firstOrFail();

        return [
            'vehicle' => $replayVehicle,
            'warning' => $this->buildFinalPaymentWarning($replayVehicle),
        ];
    }

    /**
     * @param  array{amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     */
    private function isSameFinalPaymentRequest(MoneyEntry $entry, int $vehicleId, array $effectiveData): bool
    {
        if ((int) $entry->vehicle_id !== $vehicleId) {
            return false;
        }

        if ($entry->direction !== 'income' || $entry->category !== '尾款收入') {
            return false;
        }

        if ((int) $entry->amount !== $effectiveData['amount']) {
            return false;
        }

        if ((int) $entry->cash_account_id !== $effectiveData['cash_account_id']) {
            return false;
        }

        if ($entry->description !== $effectiveData['description']) {
            return false;
        }

        // A retry that omits entry_date is a replay of "whatever date the entry was
        // originally recorded on" — it must not be forced to match "today", which would
        // make retries fail purely because they crossed midnight.
        if ($effectiveData['entry_date_was_supplied'] && $entry->entry_date?->toDateString() !== $effectiveData['entry_date']) {
            return false;
        }

        return true;
    }

    private function isIdempotencyKeyUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        if ($sqlState !== '23000') {
            return false;
        }

        return str_contains($e->getMessage(), 'idempotency_key');
    }

    private function buildFinalPaymentWarning(Vehicle $vehicle): ?string
    {
        $incomeTotal = (int) MoneyEntry::query()
            ->approved()
            ->where('vehicle_id', $vehicle->id)
            ->where('direction', 'income')
            ->sum('amount');

        if ($vehicle->sold_price !== null && $incomeTotal !== (int) $vehicle->sold_price) {
            return '訂金加尾款總額與成交價不相符，請確認金額是否正確';
        }

        return null;
    }

    /**
     * @return array{printed_at: string, vehicle: Vehicle}
     */
    public function printIntakeData(Vehicle $vehicle): array
    {
        return [
            'printed_at' => now()->toISOString(),
            'vehicle' => $vehicle,
        ];
    }

    /**
     * @return array{printed_at: string, vehicle: Vehicle, summary: array{income_total: int, expense_total: int, gross_profit: int}, money_entries: \Illuminate\Support\Collection<int, MoneyEntry>}
     */
    public function printClosingData(Vehicle $vehicle): array
    {
        $this->assertStatus($vehicle, 'sold', '只有已售出的車輛可以列印成交結案收支明細');

        $entries = MoneyEntry::query()
            ->approved()
            ->where('vehicle_id', $vehicle->id)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        return [
            'printed_at' => now()->toISOString(),
            'vehicle' => $vehicle,
            'summary' => $this->financialSummary($vehicle),
            'money_entries' => $entries,
        ];
    }

    private function generateStockNo(): string
    {
        $prefix = 'V'.now()->format('Ymd');

        $lastStockNo = Vehicle::query()
            ->where('stock_no', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderByDesc('stock_no')
            ->value('stock_no');

        $sequence = $lastStockNo ? ((int) substr($lastStockNo, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
