<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\UsernameRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrentUserProfileRequest extends FormRequest
{
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => UsernameRules::rules($user instanceof User ? $user : null),
        ];
    }

    public function messages(): array
    {
        return UsernameRules::messages();
    }
}
