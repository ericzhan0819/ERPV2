<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'must_change_password' => $this->must_change_password,
            'role' => $this->role,
            'is_admin' => $this->is_admin,
            'is_active' => $this->is_active,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'hire_date' => $this->hire_date?->toDateString(),
            'notes' => $this->notes,
        ];
    }
}
