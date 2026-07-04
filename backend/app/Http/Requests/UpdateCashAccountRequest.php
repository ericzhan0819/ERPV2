<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCashAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:cash,bank,other'],
            'opening_balance' => ['required', 'integer', 'min:0'],
            // 狀態只能透過 PATCH /cash-accounts/{id}/status 變更；此處明確拒絕，
            // 避免舊版或快取中的前端呼叫此端點時被靜默忽略而誤以為狀態已變更。
            'is_active' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_active.prohibited' => '啟用狀態請改用 PATCH /api/cash-accounts/{id}/status 變更',
        ];
    }
}
