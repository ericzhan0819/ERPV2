<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexCustomerRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService,
    ) {}

    public function index(IndexCustomerRequest $request): AnonymousResourceCollection
    {
        return CustomerResource::collection($this->customerService->listCustomers($request->validated()));
    }

    public function store(StoreCustomerRequest $request): CustomerResource
    {
        $customer = $this->customerService->createCustomer($request->validated(), $request->user()->id);

        return new CustomerResource($customer);
    }

    public function show(Customer $customer, Request $request): JsonResponse
    {
        $canSeeFinancials = $request->user()?->canViewFinancials() ?? false;

        $mapVehicle = function ($vehicle) use ($canSeeFinancials) {
            $row = [
                'id' => $vehicle->id,
                'stock_no' => $vehicle->stock_no,
                'status' => $vehicle->status,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'sold_at' => $vehicle->sold_at?->toISOString(),
            ];

            if ($canSeeFinancials) {
                $row['sold_price'] = $vehicle->sold_price;
            }

            return $row;
        };

        return response()->json([
            'customer' => new CustomerResource($customer),
            'vehicles_as_seller' => $customer->vehiclesAsSeller()->orderByDesc('created_at')->get()->map($mapVehicle),
            'vehicles_as_buyer' => $customer->vehiclesAsBuyer()->orderByDesc('created_at')->get()->map($mapVehicle),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->customerService->updateCustomer($customer, $request->validated(), $request->user()->id);

        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->customerService->deleteCustomer($customer);

        return response()->json(['message' => '客戶已刪除']);
    }
}
