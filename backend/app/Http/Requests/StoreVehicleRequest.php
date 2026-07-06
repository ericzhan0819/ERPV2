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
            // 只有勾選同步購車付款時，實際寫入 money_entries.idempotency_key（欄位長度
            // 100）的鍵才會是 "{idempotency_key}:initial-payment"（衍生後綴 16 字），
            // 這種情況下上限必須收緊為 100 - 16 = 84，否則衍生鍵會超出資料庫欄位長度，
            // 導致原本合法的建車請求在寫入付款時噴出資料庫例外並整筆回滾。純建車（無
            // 付款）不會產生這把衍生鍵，維持原本 max:100 即可，不需要無謂地拒絕
            // 85~100 字元的合法 key。
            'idempotency_key' => ['required', 'string', 'max:'.($this->hasInitialPurchasePayment() ? 84 : 100)],
            'initial_purchase_payment' => ['nullable', 'array'],
            'initial_purchase_payment.amount' => ['required_with:initial_purchase_payment', 'integer', 'min:1'],
            'initial_purchase_payment.cash_account_id' => ['required_with:initial_purchase_payment', 'integer', 'exists:cash_accounts,id'],
            'initial_purchase_payment.entry_date' => ['nullable', 'date'],
            'initial_purchase_payment.description' => ['nullable', 'string'],
        ];
    }

    private function hasInitialPurchasePayment(): bool
    {
        return ! empty($this->input('initial_purchase_payment'));
    }
}
