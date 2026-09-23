<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertSalaryProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_salary' => ['required', 'integer', 'min:0'],
            'fixed_allowance' => ['required', 'integer', 'min:0'],
            'labor_insurance_deduction' => ['required', 'integer', 'min:0'],
            'health_insurance_deduction' => ['required', 'integer', 'min:0'],
            'commission_enabled' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'user_id' => ['prohibited'],
            'salary_period_id' => ['prohibited'],
            'eligible_sales_count' => ['prohibited'],
            'sales_bonus_bps_snapshot' => ['prohibited'],
            'base_salary_snapshot' => ['prohibited'],
            'fixed_allowance_snapshot' => ['prohibited'],
            'labor_insurance_deduction_snapshot' => ['prohibited'],
            'health_insurance_deduction_snapshot' => ['prohibited'],
            'purchase_bonus_total' => ['prohibited'],
            'sales_bonus_total' => ['prohibited'],
            'manual_addition_total' => ['prohibited'],
            'manual_deduction_total' => ['prohibited'],
            'gross_pay' => ['prohibited'],
            'deduction_total' => ['prohibited'],
            'net_pay' => ['prohibited'],
            'status' => ['prohibited'],
            'money_entry_id' => ['prohibited'],
            'confirmed_by' => ['prohibited'],
            'paid_by' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.required' => ':attribute 為必填欄位',
            '*.integer' => ':attribute 必須是整數',
            '*.min' => ':attribute 不可小於 0',
            '*.boolean' => ':attribute 必須是布林值',
            '*.prohibited' => ':attribute 不允許由前端寫入',
        ];
    }

    public function attributes(): array
    {
        return [
            'base_salary' => '月底薪',
            'fixed_allowance' => '固定津貼',
            'labor_insurance_deduction' => '勞保扣款',
            'health_insurance_deduction' => '健保扣款',
            'commission_enabled' => '是否啟用獎金',
            'is_active' => '薪資設定狀態',
        ];
    }
}
