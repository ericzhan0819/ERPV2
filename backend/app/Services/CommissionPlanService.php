<?php

namespace App\Services;

use App\Models\CommissionPlan;
use App\Models\User;
use App\Support\CommissionPlanRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
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
     * 某月份採用該月第一天以前生效、且生效日最新的啟用方案；生效日相同時取 id 較新的方案。
     * periodMonth 必須使用系統共用的 YYYY-MM 薪資月份格式。
     */
    public function findEffectiveForMonth(string $periodMonth): ?CommissionPlan
    {
        return $this->effectiveForMonthQuery($periodMonth)
            ->with('tiers')
            ->first();
    }

    /**
     * 草稿建立會在 transaction 內先以 locking read 固定方案，避免在候選車上鎖前
     * 由一般 SELECT 提早建立 MySQL REPEATABLE READ 的 read view。tiers 待車輛鎖定後載入。
     */
    public function findEffectiveForMonthForUpdate(string $periodMonth): ?CommissionPlan
    {
        return $this->effectiveForMonthQuery($periodMonth)->lockForUpdate()->first();
    }

    private function effectiveForMonthQuery(string $periodMonth): Builder
    {
        return CommissionPlan::query()
            ->activeForMonth($periodMonth)
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
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

        try {
            return DB::transaction(function () use ($actor, $data, $tiers) {
                // 名稱重複時先讓例外離開，整個方案與級距交易才會先完整回滾，再查詢勝出的資料。
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
        } catch (QueryException $exception) {
            if (! $this->isCommissionPlanNameUniqueViolation($exception)) {
                throw $exception;
            }

            $this->rejectRacedDuplicateNameAfterRollback($exception, (string) $data['name']);
        }
    }

    private function rejectRacedDuplicateNameAfterRollback(QueryException $original, string $name): never
    {
        DB::transaction(function () use ($original, $name) {
            // 回滾後必須在新的交易中加鎖讀取，MySQL 的 REPEATABLE READ 才看得到另一個請求已提交的方案。
            $winner = CommissionPlan::query()
                ->where('name', $name)
                ->lockForUpdate()
                ->first();

            if (! $winner) {
                throw $original;
            }

            throw ValidationException::withMessages([
                'name' => ['獎金方案名稱已由另一個請求建立，請使用其他名稱'],
            ]);
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function isCommissionPlanNameUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && str_contains($exception->getMessage(), 'commission_plans')
            && str_contains($exception->getMessage(), 'name');
    }
}
