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
            // 用 missing（而非 prohibited）是因為 prohibited 等同 required 的反向規則，
            // 對 null / 空字串 / 空陣列會放行，仍會造成「假成功」；missing 要求鍵完全不存在。
            'is_active' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_active.missing' => '啟用狀態請改用 PATCH /api/cash-accounts/{id}/status 變更',
        ];
    }
}
