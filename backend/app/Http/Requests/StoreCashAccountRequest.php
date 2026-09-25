<?php

namespace App\Http\Requests;

use App\Support\MoneyMath;
use Illuminate\Foundation\Http\FormRequest;

class StoreCashAccountRequest extends FormRequest
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
            'opening_balance' => ['required', 'integer', 'min:0', 'max:'.MoneyMath::MAX_AMOUNT],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'opening_balance.max' => '金額不得超過 999,999,999,999 元',
        ];
    }
}
