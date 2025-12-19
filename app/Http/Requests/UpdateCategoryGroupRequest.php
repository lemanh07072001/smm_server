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
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:100', Rule::unique('category_groups', 'slug')->ignore($id)],
            'icon' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'Category không tồn tại.',
            'name.max' => 'Tên không được vượt quá 100 ký tự.',
            'slug.max' => 'Slug không được vượt quá 100 ký tự.',
            'slug.unique' => 'Slug đã tồn tại.',
            'is_active.boolean' => 'Trạng thái phải là true hoặc false.',
        ];
    }
}
