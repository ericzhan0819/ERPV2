<?php

namespace App\Http\Requests;

use App\Support\MoneyMath;
use Illuminate\Foundation\Http\FormRequest;

class DepositVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:'.MoneyMath::MAX_AMOUNT],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'entry_date' => ['nullable', 'date'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.max' => '金額不得超過 999,999,999,999 元',
        ];
    }
}
