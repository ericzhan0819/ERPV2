<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexVehicleRequest extends FormRequest
{
    private const STATUSES = ['preparing', 'listed', 'reserved', 'sold', 'cancelled'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $statusRules = is_array($this->input('status'))
            ? ['nullable', 'array', 'min:1', 'max:5']
            : ['nullable', 'string', Rule::in(self::STATUSES)];

        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => $statusRules,
            'status.*' => ['string', 'distinct', Rule::in(self::STATUSES)],
            'is_preparation_completed' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');
        $preparationCompleted = $this->input('is_preparation_completed');

        // URL 使用逗號保存多選狀態；仍接受既有單一 status 與 status[] API 呼叫。
        if (is_string($status) && str_contains($status, ',')) {
            $this->merge([
                'status' => array_values(array_filter(
                    array_map('trim', explode(',', $status)),
                    fn (string $value): bool => $value !== '',
                )),
            ]);
        }

        // Laravel boolean rule 不接受 URL 常見的 "true"／"false" 字串；只轉換已知
        // 合法表示，其他輸入保留給 validation 明確拒絕。
        if (is_string($preparationCompleted) && in_array($preparationCompleted, ['true', 'false'], true)) {
            $this->merge(['is_preparation_completed' => $preparationCompleted === 'true']);
        }
    }
}
