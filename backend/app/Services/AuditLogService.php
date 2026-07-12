<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogService
{
    /**
     * Values for these fields must never be persisted in audit records.
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'remember_token',
        'idempotency_key',
        'idempotency_payload',
    ];

    private const NOISE_FIELDS = [
        'created_at',
        'updated_at',
    ];

    /**
     * @var array<class-string<Model>, string>
     */
    private const SUBJECT_TYPE_MAP = [
        User::class => 'user',
        Vehicle::class => 'vehicle',
        VehiclePhoto::class => 'vehicle_photo',
        MoneyEntry::class => 'money_entry',
        CashAccount::class => 'cash_account',
        Customer::class => 'customer',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listLogs(array $filters): LengthAwarePaginator
    {
        $query = AuditLog::query();

        if (! empty($filters['actor_id'])) {
            $query->where('actor_id', $filters['actor_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('actor_name', 'like', "%{$search}%")
                    ->orWhere('actor_email', 'like', "%{$search}%")
                    ->orWhere('subject_label', 'like', "%{$search}%");
            });
        }

        return $query->newest()->paginate($filters['per_page'] ?? 30);
    }

    public function recordModelEvent(Model $model, string $action): AuditLog
    {
        $beforeValues = null;
        $afterValues = null;

        if ($action === AuditLog::ACTION_CREATED) {
            $afterValues = $this->sanitizeValues($model->getAttributes());
        } elseif ($action === AuditLog::ACTION_UPDATED) {
            $changes = $model->getChanges();
            $beforeValues = $this->sanitizeValues(array_intersect_key($model->getRawOriginal(), $changes));
            $afterValues = $this->sanitizeValues($changes);
        } elseif ($action === AuditLog::ACTION_DELETED) {
            $beforeValues = $this->sanitizeValues($model->getAttributes());
        }

        return $this->record(
            action: $action,
            subjectType: self::SUBJECT_TYPE_MAP[$model::class] ?? class_basename($model),
            subjectId: $model->getKey() !== null ? (int) $model->getKey() : null,
            subjectLabel: $this->subjectLabel($model),
            beforeValues: $beforeValues ?: null,
            afterValues: $afterValues ?: null,
        );
    }

    public function recordAuthentication(string $action, User $user): AuditLog
    {
        return $this->record(
            action: $action,
            subjectType: 'authentication',
            subjectId: $user->id,
            subjectLabel: $user->name.'（'.$user->email.'）',
            actor: $user,
        );
    }

    /**
     * VehiclePhoto 沒有註冊通用的 AuditObserver：一次上傳批次會建立多筆「尚未提交、
     * 暫不可見」的中途 row，idempotency 失敗回滾、批次放棄清理（sweep）、tombstone
     * 重試清理（purge）都會觸發大量 model create/update/delete 事件，若照搬其他
     * model 的做法全部自動記錄，稽核紀錄會被系統內部狀態機雜訊淹沒，真正的使用者
     * 操作（上傳、刪除、排序、換封面）反而被稀釋掉。因此排序這個動作改由呼叫端
     * （VehiclePhotoService::reorder()）在真正完成後手動呼叫這個方法記一筆，而不是
     * 依賴 Eloquent 事件；上傳／刪除／換封面則由呼叫端在確定動作真正生效後直接呼叫
     * recordModelEvent()。
     *
     * @param  array<int, int>  $photoIds
     */
    public function recordVehiclePhotoReorder(Vehicle $vehicle, array $photoIds): AuditLog
    {
        return $this->record(
            action: AuditLog::ACTION_UPDATED,
            subjectType: 'vehicle_photo',
            subjectId: null,
            subjectLabel: '車輛照片排序：'.$this->subjectLabel($vehicle),
            afterValues: ['vehicle_id' => $vehicle->id, 'photo_order' => $photoIds],
        );
    }

    /**
     * @param  array<string, mixed>|null  $beforeValues
     * @param  array<string, mixed>|null  $afterValues
     */
    private function record(
        string $action,
        string $subjectType,
        ?int $subjectId,
        ?string $subjectLabel,
        ?array $beforeValues = null,
        ?array $afterValues = null,
        ?User $actor = null,
    ): AuditLog {
        $resolvedActor = $actor ?? Auth::user();
        $request = app()->bound('request') ? request() : null;

        return AuditLog::query()->create([
            'actor_id' => $resolvedActor?->id,
            'actor_name' => $resolvedActor?->name,
            'actor_email' => $resolvedActor?->email,
            'actor_role' => $resolvedActor?->role,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'before_values' => $beforeValues,
            'after_values' => $afterValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_method' => $request?->method(),
            'request_path' => $request?->path(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitizeValues(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            if (in_array($key, self::SENSITIVE_FIELDS, true) || in_array($key, self::NOISE_FIELDS, true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeValues($value);
            } elseif ($value instanceof DateTimeInterface) {
                $sanitized[$key] = $value->format(DATE_ATOM);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function subjectLabel(Model $model): ?string
    {
        return match (true) {
            $model instanceof User => $model->name.'（'.$model->email.'）',
            $model instanceof Vehicle => trim($model->stock_no.' '.$model->brand.' '.$model->model),
            $model instanceof VehiclePhoto => trim(($model->vehicle?->stock_no ?? ('vehicle#'.$model->vehicle_id)).' '.$model->original_filename),
            $model instanceof MoneyEntry => $model->category.' #'.$model->getKey(),
            $model instanceof CashAccount => $model->name,
            $model instanceof Customer => $model->name,
            default => (string) $model->getKey(),
        };
    }
}
