<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'buyer_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'sold_price' => ['required', 'integer', 'min:1'],
            'deposit_amount' => ['required', 'integer', 'min:1'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'entry_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'sales_agent_id' => [
                Rule::requiredIf(fn () => $this->user()?->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]) ?? false),
                Rule::prohibitedIf(fn () => $this->user()?->isSales() ?? false),
                'integer',
                'exists:users,id',
            ],
        ];
    }
}
