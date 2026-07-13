<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SalaryProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryProfileService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(private readonly AuditLogService $auditLogService) {}

    /** @return Collection<int, SalaryProfile> */
    public function listProfiles(): Collection
    {
        return SalaryProfile::query()
            ->with('user:id,name,email,role,is_active')
            ->orderBy('user_id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertProfile(User $actor, User $user, array $data): SalaryProfile
    {
        try {
            return DB::transaction(function () use ($actor, $user, $data) {
                // Keep the same child-then-parent lock order as UserService::deleteUser().
                // On a first insert there may be no child row to lock, so the unique
                // user_id constraint remains the final arbiter for concurrent creates.
                $profile = SalaryProfile::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

                $this->assertCanActivateForUser($lockedUser, $data);

                $action = $profile === null ? AuditLog::ACTION_CREATED : AuditLog::ACTION_UPDATED;
                $profile ??= new SalaryProfile(['user_id' => $lockedUser->id]);
                $profile->fill($data);

                if (! $profile->exists || $profile->isDirty()) {
                    $changedFields = $profile->exists
                        ? array_keys($profile->getDirty())
                        : array_keys($profile->getAttributes());
                    // Let a duplicate-key QueryException escape this closure so the
                    // transaction fully rolls back before the winner is re-read.
                    $profile->save();
                    $this->auditLogService->recordSalaryProfileChange($profile, $action, $changedFields, $actor);
                }

                return $profile->load('user:id,name,email,role,is_active');
            }, self::TRANSACTION_ATTEMPTS);
        } catch (QueryException $exception) {
            if (! $this->isSalaryProfileUserUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->replayRacedProfileUpsertAfterRollback($exception, $user, $data);
        }
    }

    /** @param array<string, mixed> $data */
    private function replayRacedProfileUpsertAfterRollback(
        QueryException $original,
        User $user,
        array $data,
    ): SalaryProfile {
        return DB::transaction(function () use ($original, $user, $data) {
            // This must be a fresh transaction/locking read. Reusing the failed
            // transaction could retain an old MySQL REPEATABLE READ snapshot and
            // incorrectly miss the committed winner.
            $winner = SalaryProfile::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $winner) {
                throw $original;
            }

            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertCanActivateForUser($lockedUser, $data);

            if (! $this->hasSameProfilePayload($winner, $data)) {
                throw ValidationException::withMessages([
                    'user' => ['此使用者的薪資設定已由另一個請求建立且內容不同，請重新整理後再試'],
                ]);
            }

            return $winner->load('user:id,name,email,role,is_active');
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** @param array<string, mixed> $data */
    private function assertCanActivateForUser(User $user, array $data): void
    {
        if (($data['is_active'] ?? true) && ! $user->is_active) {
            throw ValidationException::withMessages([
                'is_active' => ['停用中的使用者不可啟用薪資設定'],
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function hasSameProfilePayload(SalaryProfile $profile, array $data): bool
    {
        return (int) $profile->base_salary === (int) $data['base_salary']
            && (int) $profile->fixed_allowance === (int) $data['fixed_allowance']
            && (int) $profile->labor_insurance_deduction === (int) $data['labor_insurance_deduction']
            && (int) $profile->health_insurance_deduction === (int) $data['health_insurance_deduction']
            && (bool) $profile->commission_enabled === (bool) $data['commission_enabled']
            && (bool) $profile->is_active === (bool) $data['is_active'];
    }

    private function isSalaryProfileUserUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && str_contains($exception->getMessage(), 'salary_profiles')
            && str_contains($exception->getMessage(), 'user_id');
    }
}
