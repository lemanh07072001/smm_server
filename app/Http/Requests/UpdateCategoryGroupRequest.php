<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->is_active,
            ]);
        }

        if ($this->has('group_id') && is_string($this->group_id) && $this->group_id !== '') {
            $this->merge([
                'group_id' => (int) $this->group_id,
            ]);
        }

        if ($this->has('category_id') && is_string($this->category_id) && $this->category_id !== '') {
            $this->merge([
                'category_id' => (int) $this->category_id,
            ]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max' => 'Tên không được vượt quá 100 ký tự.',
            'image.image' => 'File phải là hình ảnh.',
            'image.max' => 'Hình ảnh không được vượt quá 2MB.',
            'is_active.boolean' => 'Trạng thái phải là true hoặc false.',
        ];
    }
}
