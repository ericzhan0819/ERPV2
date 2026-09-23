<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role,
                'is_active' => $this->user->is_active,
            ]),
            'base_salary' => $this->base_salary,
            'fixed_allowance' => $this->fixed_allowance,
            'labor_insurance_deduction' => $this->labor_insurance_deduction,
            'health_insurance_deduction' => $this->health_insurance_deduction,
            'commission_enabled' => $this->commission_enabled,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
