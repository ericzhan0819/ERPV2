<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexVehicleRequest;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Services\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VehicleController extends Controller
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function index(IndexVehicleRequest $request): AnonymousResourceCollection
    {
        $vehicles = $this->vehicleService->listVehicles($request->validated());

        return VehicleResource::collection($vehicles);
    }

    public function store(StoreVehicleRequest $request): VehicleResource
    {
        $vehicle = $this->vehicleService->createVehicle($request->validated(), $request->user()->id);

        return new VehicleResource($vehicle);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $entries = $vehicle->moneyEntries()
            ->with('cashAccount:id,name,type')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'entry_date' => $entry->entry_date?->toDateString(),
                'direction' => $entry->direction,
                'category' => $entry->category,
                'amount' => $entry->amount,
                'counterparty_name' => $entry->counterparty_name,
                'description' => $entry->description,
                'cash_account' => $entry->cashAccount ? [
                    'id' => $entry->cashAccount->id,
                    'name' => $entry->cashAccount->name,
                    'type' => $entry->cashAccount->type,
                ] : null,
            ]);

        return response()->json([
            'vehicle' => new VehicleResource($vehicle),
            'summary' => $this->vehicleService->financialSummary($vehicle),
            'money_entries' => $entries,
        ]);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        $vehicle = $this->vehicleService->updateVehicle($vehicle, $request->validated(), $request->user()->id);

        return new VehicleResource($vehicle);
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $this->vehicleService->deleteVehicle($vehicle);

        return response()->json(['message' => '車輛已刪除']);
    }
}
