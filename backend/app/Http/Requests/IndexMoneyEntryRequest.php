<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexMoneyEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
            'direction' => ['nullable', 'string', 'in:income,expense'],
            'category' => ['nullable', 'string', 'max:255'],
            'approval_status' => ['nullable', 'string', 'in:approved,pending,rejected'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
