<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrentUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => '請輸入目前密碼',
            'current_password.string' => '目前密碼格式不正確',
            'current_password.current_password' => '目前密碼不正確',
            'password.required' => '請輸入新密碼',
            'password.string' => '新密碼格式不正確',
            'password.min' => '新密碼至少需要 8 個字元',
            'password.confirmed' => '新密碼與確認密碼不一致',
        ];
    }
}
