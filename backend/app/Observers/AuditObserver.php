<?php

namespace App\Observers;

use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function created(Model $model): void
    {
        $this->auditLogService->recordModelEvent($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->auditLogService->recordModelEvent($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->auditLogService->recordModelEvent($model, 'deleted');
    }
}
