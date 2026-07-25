<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrentUserPasswordRequest extends FormRequest
{
    private const SEPARATE_WORKFLOW_FIELDS = [
        'name' => '顯示名稱',
        'username' => '帳號名稱',
        'email' => 'Email',
        'role' => '角色',
        'is_admin' => '管理員狀態',
        'is_active' => '啟用狀態',
        'phone' => '電話',
        'job_title' => '職稱',
        'hire_date' => '到職日',
        'notes' => '備註',
        'must_change_password' => '強制改密碼狀態',
    ];

    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        $rules = [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ];

        foreach (array_keys(self::SEPARATE_WORKFLOW_FIELDS) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'current_password.required' => '請輸入目前密碼',
            'current_password.string' => '目前密碼格式不正確',
            'current_password.current_password' => '目前密碼不正確',
            'password.required' => '請輸入新密碼',
            'password.string' => '新密碼格式不正確',
            'password.min' => '新密碼至少需要 8 個字元',
            'password.confirmed' => '新密碼與確認密碼不一致',
            'password.different' => '新密碼不可與目前密碼相同',
        ];

        foreach (self::SEPARATE_WORKFLOW_FIELDS as $field => $label) {
            $messages[$field.'.missing'] = $label.'不可由修改密碼流程變更';
        }

        return $messages;
    }
}
