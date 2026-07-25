<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8'],
            'must_change_password' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [
            'must_change_password.missing' => '管理員重設密碼後固定需要使用者再次修改密碼',
        ];
    }
}
