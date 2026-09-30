<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreVehiclePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $config = config('vehicle_photos');

        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'photos' => ['required', 'array', 'min:1', 'max:'.$config['max_files_per_upload']],
            'photos.*' => [
                'bail',
                'required',
                'file',
                'max:'.$config['max_file_size_kb'],
                'mimes:'.implode(',', $config['allowed_extensions']),
                function (string $attribute, UploadedFile $file, Closure $fail): void {
                    $name = $file->getClientOriginalName();
                    if (! mb_check_encoding($name, 'UTF-8')) {
                        $fail('照片檔名必須是有效的 UTF-8 文字。');
                    } elseif (mb_strlen($name, 'UTF-8') > 255) {
                        $fail('照片檔名不可超過 255 字元。');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        $config = config('vehicle_photos');

        return [
            'idempotency_key.required' => '缺少 idempotency_key。',
            'idempotency_key.string' => 'idempotency_key 格式錯誤。',
            'idempotency_key.max' => 'idempotency_key 長度不可超過 100 字元。',
            'photos.required' => '請至少選擇一張照片。',
            'photos.array' => '照片格式錯誤。',
            'photos.min' => '請至少選擇一張照片。',
            'photos.max' => "單次上傳最多 {$config['max_files_per_upload']} 張照片。",
            'photos.*.required' => '照片檔案不可為空。',
            'photos.*.file' => '請上傳有效的檔案。',
            'photos.*.max' => '單張照片檔案大小不可超過 8MB。',
            'photos.*.mimes' => '照片格式僅接受 jpg、jpeg、png、webp。',
        ];
    }
}
