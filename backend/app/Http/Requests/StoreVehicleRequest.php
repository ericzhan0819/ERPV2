<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'license_plate' => ['nullable', 'string', 'max:255', 'required_without:vin'],
            'vin' => ['nullable', 'string', 'max:255', 'required_without:license_plate'],
            'mileage_km' => ['nullable', 'integer', 'min:0'],
            'color' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_source_type' => ['nullable', 'string', 'max:255'],
            'seller_name' => ['nullable', 'string', 'max:255'],
            'seller_phone' => ['nullable', 'string', 'max:255'],
            'seller_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'purchase_price' => ['nullable', 'integer', 'min:0'],
            'asking_price' => ['nullable', 'integer', 'min:0'],
            'floor_price' => ['nullable', 'integer', 'min:0'],
            'sales_note' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
