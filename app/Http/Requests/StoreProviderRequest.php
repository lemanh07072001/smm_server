<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'code' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[a-z0-9_-]+$/', 'unique:providers,code'],
            'api_url' => ['required', 'url', 'max:500'],
            'api_key' => ['required', 'string'],
            'balance' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tên provider là bắt buộc.',
            'name.min' => 'Tên provider phải có ít nhất 2 ký tự.',
            'name.max' => 'Tên provider không được vượt quá 100 ký tự.',
            'code.required' => 'Code là bắt buộc.',
            'code.min' => 'Code phải có ít nhất 2 ký tự.',
            'code.max' => 'Code không được vượt quá 50 ký tự.',
            'code.regex' => 'Code chỉ được chứa chữ thường, số, dấu gạch ngang và gạch dưới.',
            'code.unique' => 'Code đã tồn tại.',
            'api_url.required' => 'API URL là bắt buộc.',
            'api_url.url' => 'API URL không hợp lệ.',
            'api_key.required' => 'API Key là bắt buộc.',
            'is_active.required' => 'Trạng thái là bắt buộc.',
            'image.image' => 'File phải là hình ảnh.',
            'image.mimes' => 'Ảnh phải có định dạng: jpeg, png, jpg, gif, webp.',
            'image.max' => 'Ảnh không được vượt quá 2MB.',
        ];
    }
}
