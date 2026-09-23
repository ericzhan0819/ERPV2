<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class UsernameRules
{
    /**
     * 保留非字串型別讓 Validator 回報型別錯誤；只有合法可正規化的輸入才轉小寫與去除頭尾空白。
     */
    public static function normalizeInput(mixed $value): mixed
    {
        if ($value !== null && ! is_string($value)) {
            return $value;
        }

        return User::normalizeUsername($value);
    }

    /**
     * @return array<int, string|Unique>
     */
    public static function rules(?User $ignoreUser = null): array
    {
        $unique = Rule::unique('users', 'username');

        if ($ignoreUser !== null) {
            $unique->ignore($ignoreUser->getKey());
        }

        return [
            'nullable',
            'string',
            'min:3',
            'max:30',
            'regex:/\A[a-z0-9._-]+\z/D',
            $unique,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'username.string' => '帳號名稱必須是文字',
            'username.min' => '帳號名稱至少需要 3 個字元',
            'username.max' => '帳號名稱不可超過 30 個字元',
            'username.regex' => '帳號名稱只能包含小寫英文字母、數字、句點、底線與連字號',
            'username.unique' => '此帳號名稱已被使用',
        ];
    }
}
