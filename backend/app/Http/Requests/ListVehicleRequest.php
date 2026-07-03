<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asking_price' => ['required', 'integer', 'min:0'],
            'floor_price' => ['nullable', 'integer', 'min:0'],
            'listing_date' => ['nullable', 'date'],
            'sales_note' => ['nullable', 'string'],
        ];
    }
}
