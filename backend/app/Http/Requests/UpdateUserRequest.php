<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            // 啟用狀態與管理員權限只能分別透過 PATCH /users/{id}/status 與
            // PATCH /users/{id}/role 變更；此處明確拒絕，避免舊版或快取中的前端
            // 呼叫此端點時被靜默忽略而誤以為狀態／權限已變更。
            // 用 missing（而非 prohibited）是因為 prohibited 對 null / 空字串 / 空陣列會放行，
            // 仍會造成「假成功」；missing 要求鍵完全不存在。
            'is_active' => ['missing'],
            'is_admin' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_active.missing' => '啟用狀態請改用 PATCH /api/users/{id}/status 變更',
            'is_admin.missing' => '管理員權限請改用 PATCH /api/users/{id}/role 變更',
        ];
    }
}
