<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderVehiclePhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo_ids' => ['required', 'array', 'min:1'],
            'photo_ids.*' => ['required', 'integer', 'distinct', 'exists:vehicle_photos,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo_ids.required' => '請提供排序後的照片清單。',
            'photo_ids.array' => '排序清單格式錯誤。',
            'photo_ids.min' => '請提供排序後的照片清單。',
            'photo_ids.*.required' => '照片 ID 不可為空。',
            'photo_ids.*.integer' => '照片 ID 格式錯誤。',
            'photo_ids.*.distinct' => '排序清單不可包含重複的照片。',
            'photo_ids.*.exists' => '排序清單包含不存在的照片。',
        ];
    }
}
