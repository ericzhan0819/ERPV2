<?php

namespace App\Services;

use App\Models\CommissionPlan;
use App\Models\User;
use App\Support\CommissionPlanRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CommissionPlanService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(private readonly AuditLogService $auditLogService) {}

    /** @return Collection<int, CommissionPlan> */
    public function listPlans(): Collection
    {
        return CommissionPlan::query()
            ->with(['tiers', 'createdBy:id,name'])
            ->withCount('salaryPeriods')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    public function getPlan(CommissionPlan $plan): CommissionPlan
    {
        return $plan->load(['tiers', 'createdBy:id,name'])->loadCount('salaryPeriods');
    }

    /**
     * The plan effective for a month is the active plan with the latest
     * effective_from on or before that month's first day. If two plans share
     * that date, the newest id wins deterministically.
     */
    public function findEffectiveForMonth(string $periodMonth): ?CommissionPlan
    {
        return CommissionPlan::query()
            ->activeForMonth($periodMonth)
            ->with('tiers')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPlan(User $actor, array $data): CommissionPlan
    {
        $tiers = collect($data['tiers'])
            ->values()
            ->map(fn (array $tier, int $index) => [
                'min_sales_count' => (int) $tier['min_sales_count'],
                'sales_bonus_bps' => (int) $tier['sales_bonus_bps'],
                'sort_order' => $index + 1,
            ])
            ->all();

        try {
            CommissionPlanRules::validate(
                (int) $data['company_reserve_bps'],
                (int) $data['purchase_bonus_bps'],
                $tiers,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'tiers' => [$exception->getMessage()],
            ]);
        }

        return DB::transaction(function () use ($actor, $data, $tiers) {
            $plan = CommissionPlan::query()->create([
                'name' => $data['name'],
                'effective_from' => $data['effective_from'],
                'company_reserve_bps' => $data['company_reserve_bps'],
                'purchase_bonus_bps' => $data['purchase_bonus_bps'],
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $actor->id,
            ]);
            $plan->tiers()->createMany($tiers);
            $this->auditLogService->recordCommissionPlanCreated($plan, $actor);

            return $this->getPlan($plan);
        }, self::TRANSACTION_ATTEMPTS);
    }
}
