<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaySalaryPeriodRequest;
use App\Http\Requests\StoreSalaryAdjustmentRequest;
use App\Http\Requests\StoreSalaryPeriodRequest;
use App\Http\Resources\SalaryPeriodListResource;
use App\Http\Resources\SalaryPeriodResource;
use App\Http\Resources\SalarySettlementItemResource;
use App\Models\SalaryPeriod;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Services\SalaryPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SalaryPeriodController extends Controller
{
    public function __construct(private readonly SalaryPeriodService $salaryPeriodService) {}

    public function index(): AnonymousResourceCollection
    {
        return SalaryPeriodListResource::collection($this->salaryPeriodService->listPeriods());
    }

    public function store(StoreSalaryPeriodRequest $request): SalaryPeriodResource
    {
        $period = $this->salaryPeriodService->createDraft(
            $request->user(),
            $request->validated('period_month'),
        );

        return new SalaryPeriodResource($period);
    }

    public function show(SalaryPeriod $salaryPeriod): SalaryPeriodResource
    {
        return new SalaryPeriodResource($this->salaryPeriodService->getPeriod($salaryPeriod));
    }

    public function recalculate(Request $request, SalaryPeriod $salaryPeriod): SalaryPeriodResource
    {
        return new SalaryPeriodResource(
            $this->salaryPeriodService->recalculateDraft($request->user(), $salaryPeriod),
        );
    }

    public function storeAdjustment(
        StoreSalaryAdjustmentRequest $request,
        SalaryPeriod $salaryPeriod,
    ): SalarySettlementItemResource {
        $settlement = SalarySettlement::query()
            ->where('salary_period_id', $salaryPeriod->id)
            ->where('user_id', $request->validated('user_id'))
            ->firstOrFail();
        Gate::authorize('adjust', $settlement);

        $item = $this->salaryPeriodService->addAdjustment(
            $request->user(),
            $settlement,
            $request->safe()->except('user_id'),
        );

        return new SalarySettlementItemResource($item->load('createdBy:id,name'));
    }

    public function destroyAdjustment(
        Request $request,
        SalaryPeriod $salaryPeriod,
        SalarySettlementItem $item,
    ): JsonResponse {
        $item->loadMissing('settlement');
        abort_unless(
            $item->settlement !== null
                && (int) $item->settlement->salary_period_id === (int) $salaryPeriod->id,
            404,
        );
        Gate::authorize('deleteAdjustment', $item->settlement);

        $this->salaryPeriodService->deleteAdjustment($request->user(), $item);

        return response()->json(['message' => '手動薪資加扣項已刪除']);
    }

    public function confirm(Request $request, SalaryPeriod $salaryPeriod): SalaryPeriodResource
    {
        return new SalaryPeriodResource(
            $this->salaryPeriodService->confirm($request->user(), $salaryPeriod),
        );
    }

    public function pay(
        PaySalaryPeriodRequest $request,
        SalaryPeriod $salaryPeriod,
    ): SalaryPeriodResource {
        return new SalaryPeriodResource(
            $this->salaryPeriodService->pay($request->user(), $salaryPeriod, $request->validated()),
        );
    }
}
