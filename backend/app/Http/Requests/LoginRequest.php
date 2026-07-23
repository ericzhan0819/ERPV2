<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => '請輸入帳號或 Email',
            'login.string' => '帳號或 Email 格式不正確',
            'login.max' => '帳號或 Email 不得超過 255 個字元',
            'password.required' => '請輸入密碼',
            'password.string' => '密碼格式不正確',
        ];
    }
}
