<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', 'max:100', 'exists:settings,key'],
            'settings.*.value' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'settings.required' => 'Danh sách cài đặt là bắt buộc.',
            'settings.array' => 'Danh sách cài đặt phải là mảng.',
            'settings.min' => 'Phải có ít nhất 1 cài đặt.',
            'settings.*.key.required' => 'Key cài đặt là bắt buộc.',
            'settings.*.key.exists' => 'Key cài đặt không tồn tại.',
            'settings.*.value.max' => 'Giá trị không được vượt quá 2000 ký tự.',
        ];
    }
}
