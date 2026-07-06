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
            'displacement' => ['nullable', 'string', 'max:255'],
            'transmission' => ['nullable', 'string', 'max:255'],
            'fuel_type' => ['nullable', 'string', 'max:255'],
            'parking_location' => ['nullable', 'string', 'max:255'],
            'has_registration_document' => ['nullable', 'boolean'],
            'has_spare_key' => ['nullable', 'boolean'],
            'is_transfer_completed' => ['nullable', 'boolean'],
            'is_inspection_completed' => ['nullable', 'boolean'],
            'is_preparation_completed' => ['nullable', 'boolean'],
            'lien_note' => ['nullable', 'string'],
            'condition_note' => ['nullable', 'string'],
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
            'idempotency_key' => ['required', 'string', 'max:100'],
            'initial_purchase_payment' => ['nullable', 'array'],
            'initial_purchase_payment.amount' => ['required_with:initial_purchase_payment', 'integer', 'min:1'],
            'initial_purchase_payment.cash_account_id' => ['required_with:initial_purchase_payment', 'integer', 'exists:cash_accounts,id'],
            'initial_purchase_payment.entry_date' => ['nullable', 'date'],
            'initial_purchase_payment.description' => ['nullable', 'string'],
        ];
    }
}
