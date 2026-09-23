<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\UsernameRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrentUserProfileRequest extends FormRequest
{
    private const READ_ONLY_FIELDS = [
        'email' => 'Email',
        'role' => '角色',
        'is_admin' => '管理員狀態',
        'is_active' => '啟用狀態',
        'phone' => '電話',
        'job_title' => '職稱',
        'hire_date' => '到職日',
        'notes' => '備註',
        'must_change_password' => '強制改密碼狀態',
        'password' => '密碼',
    ];

    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('username')) {
            $this->merge([
                'username' => UsernameRules::normalizeInput($this->input('username')),
            ]);
        }
    }

    public function rules(): array
    {
        $user = $this->user();
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            // 個人資料表單採完整提交：username 鍵必須存在，null 表示明確清空。
            'username' => ['present', ...UsernameRules::rules($user instanceof User ? $user : null)],
        ];

        foreach (array_keys(self::READ_ONLY_FIELDS) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            ...UsernameRules::messages(),
            'username.present' => '請提供帳號名稱；若不設定請傳 null',
        ];

        foreach (self::READ_ONLY_FIELDS as $field => $label) {
            $messages[$field.'.missing'] = $label.'不可由我的帳號修改';
        }

        return $messages;
    }
}
