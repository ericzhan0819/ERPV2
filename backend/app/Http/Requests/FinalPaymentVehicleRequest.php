<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinalPaymentVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'entry_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
        ];
    }
}
