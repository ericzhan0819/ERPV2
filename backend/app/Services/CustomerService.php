<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function listCustomers(array $filters): LengthAwarePaginator
    {
        $query = Customer::query();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('line_id', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['customer_type'])) {
            $query->where('customer_type', $filters['customer_type']);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCustomer(array $data, int $userId): Customer
    {
        $customer = new Customer($data);
        $customer->created_by = $userId;
        $customer->updated_by = $userId;

        try {
            $customer->save();
        } catch (QueryException $exception) {
            $this->throwCustomerIdentityConflictOrRethrow($exception);
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCustomer(Customer $customer, array $data, int $userId): Customer
    {
        $customer->fill($data);
        $customer->updated_by = $userId;

        try {
            $customer->save();
        } catch (QueryException $exception) {
            $this->throwCustomerIdentityConflictOrRethrow($exception);
        }

        return $customer;
    }

    public function deleteCustomer(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            // 先鎖住客戶再檢查關聯。MySQL/InnoDB 要把車輛連到此客戶時，也必須取得父資料列的共享鎖，
            // 因此會等到這筆交易提交或回滾，避免在檢查與刪除之間又被連上一台車。
            $lockedCustomer = Customer::query()
                ->whereKey($customer->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoLinkedVehicles($lockedCustomer);

            try {
                $lockedCustomer->delete();
            } catch (QueryException $e) {
                if (! $this->isForeignKeyConstraintViolation($e)) {
                    throw $e;
                }

                // 若仍有未被前述鎖定涵蓋的競態狀況，外鍵也會擋下刪除；改回傳相同的明確訊息，
                // 不直接把資料庫原始錯誤丟給使用者。
                throw ValidationException::withMessages([
                    'customer' => ['此客戶已有關聯車輛，不得刪除'],
                ]);
            }
        });
    }

    private function assertNoLinkedVehicles(Customer $customer): void
    {
        $hasVehicles = $customer->vehiclesAsSeller()->exists() || $customer->vehiclesAsBuyer()->exists();

        if ($hasVehicles) {
            throw ValidationException::withMessages([
                'customer' => ['此客戶已有關聯車輛，不得刪除'],
            ]);
        }
    }

    private function throwCustomerIdentityConflictOrRethrow(QueryException $exception): never
    {
        if (Customer::isIdentityUniqueViolation($exception)) {
            throw ValidationException::withMessages([
                'phone' => ['已有相同姓名與電話的客戶，請直接使用既有客戶'],
            ]);
        }

        throw $exception;
    }

    private function isForeignKeyConstraintViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}
