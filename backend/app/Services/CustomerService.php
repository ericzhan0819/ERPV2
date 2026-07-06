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
        $customer->save();

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCustomer(Customer $customer, array $data, int $userId): Customer
    {
        $customer->fill($data);
        $customer->updated_by = $userId;
        $customer->save();

        return $customer;
    }

    public function deleteCustomer(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            // Lock the customer row before checking relations. Under MySQL/InnoDB, a
            // concurrent request that links a vehicle to this customer must acquire a
            // shared lock on the referenced parent row to satisfy the FK constraint, so
            // it blocks until this transaction commits/rolls back — closing the window
            // where a vehicle could be linked between the check and the delete below.
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

                // Backstop for any race the row lock above didn't catch (e.g. a
                // different locking model): the FK constraint itself refused the
                // delete, so report the same friendly, non-silent rejection instead
                // of letting the raw DB error surface.
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

    private function isForeignKeyConstraintViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}
