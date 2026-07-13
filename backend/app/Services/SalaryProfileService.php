<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SalaryProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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
        return DB::transaction(function () use ($actor, $user, $data) {
            // Keep the same child-then-parent lock order as UserService::deleteUser().
            // The unique user_id index also serializes concurrent first-time upserts.
            $profile = SalaryProfile::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if (($data['is_active'] ?? true) && ! $lockedUser->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => ['停用中的使用者不可啟用薪資設定'],
                ]);
            }

            $action = $profile === null ? AuditLog::ACTION_CREATED : AuditLog::ACTION_UPDATED;
            $profile ??= new SalaryProfile(['user_id' => $lockedUser->id]);
            $profile->fill($data);

            if (! $profile->exists || $profile->isDirty()) {
                $changedFields = $profile->exists
                    ? array_keys($profile->getDirty())
                    : array_keys($profile->getAttributes());
                $profile->save();
                $this->auditLogService->recordSalaryProfileChange($profile, $action, $changedFields, $actor);
            }

            return $profile->load('user:id,name,email,role,is_active');
        }, self::TRANSACTION_ATTEMPTS);
    }
}
