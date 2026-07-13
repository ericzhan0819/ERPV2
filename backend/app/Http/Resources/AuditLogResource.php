<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            'actor_email' => $this->actor_email,
            'actor_role' => $this->actor_role,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject_label' => $this->subject_label,
            'before_values' => $this->before_values,
            'after_values' => $this->after_values,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_method' => $this->request_method,
            'request_path' => $this->request_path,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
