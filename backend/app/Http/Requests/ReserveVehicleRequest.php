<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReserveVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:255'],
            'sold_price' => ['required', 'integer', 'min:1'],
            'deposit_amount' => ['required', 'integer', 'min:1'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'entry_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
        ];
    }
}
