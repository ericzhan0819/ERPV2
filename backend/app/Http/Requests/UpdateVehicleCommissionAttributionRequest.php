<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateVehicleCommissionAttributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_agent_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'sales_agent_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'status' => ['missing'],
            'sold_at' => ['missing'],
            'totals' => ['missing'],
            'snapshot' => ['missing'],
            'money_entry_id' => ['missing'],
            'confirmed_by' => ['missing'],
            'paid_by' => ['missing'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['purchase_agent_id', 'sales_agent_id'])) {
                    $validator->errors()->add('commission_attribution', '請至少指定收車人或賣車人');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            '*.required' => ':attribute 為必填欄位',
            '*.integer' => ':attribute 必須是整數',
            '*.exists' => '指定的:attribute不存在',
            '*.missing' => ':attribute 不允許由前端寫入',
        ];
    }

    public function attributes(): array
    {
        return [
            'purchase_agent_id' => '收車人',
            'sales_agent_id' => '賣車人',
            'status' => '車輛狀態',
            'sold_at' => '成交時間',
            'totals' => '薪資合計',
            'snapshot' => '薪資快照',
            'money_entry_id' => '薪資支出',
            'confirmed_by' => '確認人',
            'paid_by' => '發薪人',
        ];
    }
}
