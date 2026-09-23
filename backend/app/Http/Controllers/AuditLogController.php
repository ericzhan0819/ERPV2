<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function index(IndexAuditLogRequest $request): AnonymousResourceCollection
    {
        return AuditLogResource::collection($this->auditLogService->listLogs($request->validated()));
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        return new AuditLogResource($auditLog);
    }
}
