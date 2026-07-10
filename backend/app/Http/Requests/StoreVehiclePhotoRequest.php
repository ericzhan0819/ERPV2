<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'photos' => ['required', 'array', 'min:1', 'max:'.$config['max_files_per_upload']],
            'photos.*' => [
                'required',
                'file',
                'max:'.$config['max_file_size_kb'],
                'mimes:'.implode(',', $config['allowed_extensions']),
            ],
        ];
    }

    public function messages(): array
    {
        $config = config('vehicle_photos');

        return [
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
