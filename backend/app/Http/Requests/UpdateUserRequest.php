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
            // 啟用狀態與角色只能分別透過 PATCH /users/{id}/status 與
            // PATCH /users/{id}/role 變更；此處明確拒絕，避免舊版或快取中的前端
            // 呼叫此端點時被靜默忽略而誤以為狀態／角色已變更。
            // 用 missing（而非 prohibited）是因為 prohibited 對 null / 空字串 / 空陣列會放行，
            // 仍會造成「假成功」；missing 要求鍵完全不存在。
            'is_active' => ['missing'],
            'is_admin' => ['missing'],
            'role' => ['missing'],
            // 帳號名稱與首次改密碼狀態也各有專用流程，管理員的一般資料更新不可代寫。
            'username' => ['missing'],
            'must_change_password' => ['missing'],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'hire_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_active.missing' => '啟用狀態請改用 PATCH /api/users/{id}/status 變更',
            'is_admin.missing' => '角色請改用 PATCH /api/users/{id}/role 變更',
            'role.missing' => '角色請改用 PATCH /api/users/{id}/role 變更',
            'username.missing' => '帳號名稱請改用 PATCH /api/me/profile 由使用者本人變更',
            'must_change_password.missing' => '首次改密碼狀態請透過重設密碼或本人改密碼流程變更',
        ];
    }
}
