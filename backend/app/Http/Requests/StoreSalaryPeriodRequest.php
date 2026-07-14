<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreSalaryPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currentMonth = Carbon::now(config('app.timezone'))->format('Y-m');

        return [
            'period_month' => ['required', 'date_format:Y-m', "before_or_equal:{$currentMonth}"],
            'commission_plan_id' => ['missing'],
            'status' => ['missing'],
            'settlements' => ['missing'],
            'totals' => ['missing'],
            'snapshot' => ['missing'],
            'money_entry_id' => ['missing'],
            'confirmed_by' => ['missing'],
            'confirmed_at' => ['missing'],
            'paid_by' => ['missing'],
            'paid_at' => ['missing'],
            'cash_account_id' => ['missing'],
            'payment_date' => ['missing'],
            'idempotency_key' => ['missing'],
            'created_by' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [
            'period_month.required' => '結算月份為必填欄位',
            'period_month.date_format' => '結算月份格式必須為 YYYY-MM',
            'period_month.before_or_equal' => '結算月份不得晚於台北目前月份',
            '*.missing' => ':attribute 不允許由前端寫入',
        ];
    }

    public function attributes(): array
    {
        return [
            'period_month' => '結算月份',
            'commission_plan_id' => '獎金方案',
            'status' => '結算狀態',
            'settlements' => '員工結算資料',
            'totals' => '薪資合計',
            'snapshot' => '薪資快照',
            'money_entry_id' => '薪資支出',
            'confirmed_by' => '確認人',
            'confirmed_at' => '確認時間',
            'paid_by' => '發薪人',
            'paid_at' => '發薪時間',
            'cash_account_id' => '資金帳戶',
            'payment_date' => '發薪日期',
            'idempotency_key' => '冪等鍵',
            'created_by' => '建立人',
        ];
    }
}
