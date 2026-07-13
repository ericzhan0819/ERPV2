<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommissionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:commission_plans,name'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'company_reserve_bps' => ['required', 'integer', 'between:0,10000'],
            'purchase_bonus_bps' => ['required', 'integer', 'between:0,10000'],
            'is_active' => ['sometimes', 'boolean'],
            'created_by' => ['prohibited'],
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.min_sales_count' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'tiers.*.sales_bonus_bps' => ['required', 'integer', 'between:0,10000'],
            'tiers.*.sort_order' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.required' => ':attribute 為必填欄位',
            '*.integer' => ':attribute 必須是整數',
            '*.between' => ':attribute 必須介於 :min 到 :max',
            '*.prohibited' => ':attribute 不允許由前端寫入',
            'name.unique' => '獎金方案名稱已存在',
            'effective_from.date_format' => '生效日格式必須為 YYYY-MM-DD',
            'tiers.array' => '賣車級距格式不正確',
            'tiers.min' => '獎金方案至少需要一個賣車級距',
            'tiers.*.min_sales_count.distinct' => '賣車級距台數不可重複',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '方案名稱',
            'effective_from' => '生效日',
            'company_reserve_bps' => '公司保留比例',
            'purchase_bonus_bps' => '收車獎金比例',
            'is_active' => '方案狀態',
            'tiers' => '賣車級距',
            'tiers.*.min_sales_count' => '級距起始台數',
            'tiers.*.sales_bonus_bps' => '賣車獎金比例',
        ];
    }
}
