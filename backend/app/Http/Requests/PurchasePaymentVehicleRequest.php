<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchasePaymentVehicleRequest extends FormRequest
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
            'entry_date' => ['nullable', 'date'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
