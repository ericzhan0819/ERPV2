<?php

namespace App\Http\Requests;

use App\Models\SalarySettlementItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalaryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', Rule::in(SalarySettlementItem::MANUAL_TYPES)],
            'amount' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
            'salary_period_id' => ['missing'],
            'salary_settlement_id' => ['missing'],
            'vehicle_id' => ['missing'],
            'calculation_snapshot' => ['missing'],
            'created_by' => ['missing'],
            'totals' => ['missing'],
            'snapshot' => ['missing'],
            'status' => ['missing'],
            'money_entry_id' => ['missing'],
            'confirmed_by' => ['missing'],
            'paid_by' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.required' => ':attribute 為必填欄位',
            '*.integer' => ':attribute 必須是整數',
            '*.exists' => '指定的:attribute不存在',
            'type.in' => '項目類型只允許其他加給或其他扣款',
            'amount.min' => '金額必須大於 0',
            'description.max' => '說明不可超過 255 個字元',
            '*.missing' => ':attribute 不允許由前端寫入',
        ];
    }

    public function attributes(): array
    {
        return [
            'user_id' => '員工',
            'type' => '項目類型',
            'amount' => '金額',
            'description' => '說明',
            'salary_period_id' => '薪資月份',
            'salary_settlement_id' => '員工結算',
            'vehicle_id' => '關聯車輛',
            'calculation_snapshot' => '計算快照',
            'created_by' => '建立人',
            'totals' => '薪資合計',
            'snapshot' => '薪資快照',
            'status' => '結算狀態',
            'money_entry_id' => '薪資支出',
            'confirmed_by' => '確認人',
            'paid_by' => '發薪人',
        ];
    }
}
