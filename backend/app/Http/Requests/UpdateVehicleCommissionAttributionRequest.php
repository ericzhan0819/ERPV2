<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateVehicleCommissionAttributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_agent_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'sales_agent_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['purchase_agent_id', 'sales_agent_id'])) {
                    $validator->errors()->add('commission_attribution', '請至少指定收車人或賣車人');
                }
            },
        ];
    }
}
