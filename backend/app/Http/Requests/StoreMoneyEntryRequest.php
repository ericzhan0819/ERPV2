<?php

namespace App\Http\Requests;

use App\Services\MoneyEntryService;
use Illuminate\Foundation\Http\FormRequest;

class StoreMoneyEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'direction' => ['required', 'string', 'in:income,expense'],
            'category' => ['required', 'string', 'in:'.implode(',', MoneyEntryService::categories())],
            'amount' => ['required', 'integer', 'min:1'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
