<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewMoneyEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['expected_review_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']];
    }
}
