<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseSaleVehicleRequest;
use App\Http\Requests\DepositVehicleRequest;
use App\Http\Requests\FinalPaymentVehicleRequest;
use App\Http\Requests\IndexMoneyEntryRequest;
use App\Http\Requests\IndexVehicleRequest;
use App\Http\Requests\ListVehicleRequest;
use App\Http\Requests\PurchasePaymentVehicleRequest;
use App\Http\Requests\RefundVehicleRequest;
use App\Http\Requests\ReserveVehicleRequest;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Http\Requests\VehicleExpenseRequest;
use App\Http\Resources\MoneyEntryResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Services\MoneyEntryService;
use App\Services\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VehicleController extends Controller
{
    public function __construct(
        private readonly VehicleService $vehicleService,
        private readonly MoneyEntryService $moneyEntryService,
    ) {}

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

    public function show(Vehicle $vehicle, Request $request): JsonResponse
    {
        $user = $request->user();
        $canSeeFinancials = $user?->canViewFinancials() ?? false;

        if ($canSeeFinancials) {
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
                    'counterparty_name' => $entry->counterparty_name,
                    'description' => $entry->description,
                    'amount' => $entry->amount,
                    'approval_status' => $entry->approval_status,
                    'cash_account' => $entry->cashAccount ? [
                        'id' => $entry->cashAccount->id,
                        'name' => $entry->cashAccount->name,
                        'type' => $entry->cashAccount->type,
                    ] : null,
                ]);

            return response()->json([
                'vehicle' => new VehicleResource($vehicle),
                'money_entries' => $entries,
                'summary' => $this->vehicleService->financialSummary($vehicle),
            ]);
        }

        // sales：不回傳管理用 summary/完整收支明細，改回傳銷售收款安全摘要與
        // sales-safe 收支列（訂金/尾款/退款 + 自己上報的車輛支出申請）。
        if ($user?->isSales()) {
            return response()->json([
                'vehicle' => new VehicleResource($vehicle),
                'money_entries' => $this->vehicleService->salesSafeMoneyEntries($vehicle, $user),
                'sales_collection_summary' => $this->vehicleService->salesCollectionSummary($vehicle),
            ]);
        }

        // 未知角色：fail-closed，不回傳任何金額摘要或收支明細。
        return response()->json([
            'vehicle' => new VehicleResource($vehicle),
            'money_entries' => [],
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

    public function list(ListVehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        $vehicle = $this->vehicleService->listVehicle($vehicle, $request->validated(), $request->user()->id);

        return new VehicleResource($vehicle);
    }

    public function reserve(ReserveVehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        $vehicle = $this->vehicleService->reserveVehicle($vehicle, $request->validated(), $request->user()->id);

        return new VehicleResource($vehicle);
    }

    public function finalPayment(FinalPaymentVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        $result = $this->vehicleService->recordFinalPayment($vehicle, $request->validated(), $request->user()->id);

        return response()->json([
            'vehicle' => new VehicleResource($result['vehicle']),
            'warning' => $result['warning'],
        ]);
    }

    public function closeSale(CloseSaleVehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        $vehicle = $this->vehicleService->closeSale($vehicle, $request->validated(), $request->user()->id);

        return new VehicleResource($vehicle);
    }

    public function purchasePayment(PurchasePaymentVehicleRequest $request, Vehicle $vehicle): MoneyEntryResource
    {
        $entry = $this->moneyEntryService->recordVehicleShortcut(
            $vehicle,
            'expense',
            '購車付款',
            $request->validated(),
            $request->user()->id
        );

        return new MoneyEntryResource($entry);
    }

    public function expense(VehicleExpenseRequest $request, Vehicle $vehicle): MoneyEntryResource
    {
        $data = $request->validated();

        $entry = $this->moneyEntryService->recordVehicleShortcut(
            $vehicle,
            'expense',
            $data['category'],
            $data,
            $request->user()->id
        );

        return new MoneyEntryResource($entry);
    }

    public function deposit(DepositVehicleRequest $request, Vehicle $vehicle): MoneyEntryResource
    {
        $entry = $this->moneyEntryService->recordVehicleShortcut(
            $vehicle,
            'income',
            '訂金收入',
            $request->validated(),
            $request->user()->id
        );

        return new MoneyEntryResource($entry);
    }

    public function refund(RefundVehicleRequest $request, Vehicle $vehicle): MoneyEntryResource
    {
        $entry = $this->moneyEntryService->recordVehicleShortcut(
            $vehicle,
            'expense',
            '退款',
            $request->validated(),
            $request->user()->id
        );

        return new MoneyEntryResource($entry);
    }

    public function moneyEntries(IndexMoneyEntryRequest $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        $filters = array_merge($request->validated(), ['vehicle_id' => $vehicle->id]);

        return MoneyEntryResource::collection($this->moneyEntryService->listEntries($filters, $request->user()));
    }

    public function printIntake(Vehicle $vehicle): JsonResponse
    {
        $data = $this->vehicleService->printIntakeData($vehicle);

        return response()->json([
            'printed_at' => $data['printed_at'],
            'vehicle' => new VehicleResource($data['vehicle']),
        ]);
    }

    public function printClosing(Vehicle $vehicle): JsonResponse
    {
        $data = $this->vehicleService->printClosingData($vehicle);

        return response()->json([
            'printed_at' => $data['printed_at'],
            'vehicle' => new VehicleResource($data['vehicle']),
            'summary' => $data['summary'],
            'money_entries' => MoneyEntryResource::collection($data['money_entries']),
        ]);
    }
}
