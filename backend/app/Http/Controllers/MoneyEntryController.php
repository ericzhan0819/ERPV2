<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexMoneyEntryRequest;
use App\Http\Requests\StoreMoneyEntryRequest;
use App\Http\Requests\UpdateMoneyEntryRequest;
use App\Http\Resources\MoneyEntryResource;
use App\Models\MoneyEntry;
use App\Services\MoneyEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MoneyEntryController extends Controller
{
    public function __construct(private readonly MoneyEntryService $moneyEntryService) {}

    public function index(IndexMoneyEntryRequest $request): AnonymousResourceCollection
    {
        $entries = $this->moneyEntryService->listEntries($request->validated());

        return MoneyEntryResource::collection($entries);
    }

    public function store(StoreMoneyEntryRequest $request): MoneyEntryResource
    {
        $entry = $this->moneyEntryService->createEntry($request->validated(), $request->user()->id);

        return new MoneyEntryResource($entry->load(['vehicle:id,stock_no,brand,model', 'cashAccount:id,name,type']));
    }

    public function show(MoneyEntry $moneyEntry): MoneyEntryResource
    {
        return new MoneyEntryResource($moneyEntry->load(['vehicle:id,stock_no,brand,model', 'cashAccount:id,name,type']));
    }

    public function update(UpdateMoneyEntryRequest $request, MoneyEntry $moneyEntry): MoneyEntryResource
    {
        $entry = $this->moneyEntryService->updateEntry($moneyEntry, $request->validated(), $request->user()->id);

        return new MoneyEntryResource($entry->load(['vehicle:id,stock_no,brand,model', 'cashAccount:id,name,type']));
    }

    public function destroy(MoneyEntry $moneyEntry): JsonResponse
    {
        $this->moneyEntryService->deleteEntry($moneyEntry);

        return response()->json(['message' => '收支紀錄已刪除']);
    }
}
