<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaySalaryPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cash_account_id' => [
                'required',
                'integer',
                Rule::exists('cash_accounts', 'id')->where('is_active', true),
            ],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'status' => ['missing'],
            'settlements' => ['missing'],
            'totals' => ['missing'],
            'snapshot' => ['missing'],
            'money_entry_id' => ['missing'],
            'confirmed_by' => ['missing'],
            'confirmed_at' => ['missing'],
            'paid_by' => ['missing'],
            'paid_at' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.required' => ':attribute 為必填欄位',
            'cash_account_id.integer' => '資金帳戶必須是正整數',
            'cash_account_id.exists' => '請選擇啟用中的資金帳戶',
            'payment_date.date_format' => '發薪日期格式必須為 YYYY-MM-DD',
            'idempotency_key.string' => '冪等鍵格式不正確',
            'idempotency_key.max' => '冪等鍵不可超過 100 個字元',
            '*.missing' => ':attribute 不允許由前端寫入',
        ];
    }

    public function attributes(): array
    {
        return [
            'cash_account_id' => '資金帳戶',
            'payment_date' => '發薪日期',
            'idempotency_key' => '冪等鍵',
            'status' => '結算狀態',
            'settlements' => '員工結算資料',
            'totals' => '薪資合計',
            'snapshot' => '薪資快照',
            'money_entry_id' => '薪資支出',
            'confirmed_by' => '確認人',
            'confirmed_at' => '確認時間',
            'paid_by' => '發薪人',
            'paid_at' => '發薪時間',
        ];
    }
}
