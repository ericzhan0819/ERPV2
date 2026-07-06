<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        $hasVehicles = $customer->vehiclesAsSeller()->exists() || $customer->vehiclesAsBuyer()->exists();

        if ($hasVehicles) {
            throw ValidationException::withMessages([
                'customer' => ['此客戶已有關聯車輛，不得刪除'],
            ]);
        }

        $customer->delete();
    }
}
