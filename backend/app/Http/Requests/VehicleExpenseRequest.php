<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VehicleExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:維修支出,美容支出,代辦支出,拍場支出,其他支出'],
            'amount' => ['required', 'integer', 'min:1'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'entry_date' => ['nullable', 'date'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
