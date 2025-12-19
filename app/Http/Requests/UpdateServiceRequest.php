<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'category_group_id' => ['nullable', 'integer', 'exists:category_groups,id'],
            'group_id' => ['nullable', 'string'],
            'provider_service_id' => ['sometimes', 'integer', 'exists:provider_services,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sell_rate' => ['sometimes', 'numeric', 'min:0'],
            'min_quantity' => ['sometimes', 'integer', 'min:1'],
            'max_quantity' => ['sometimes', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'allow_multiple_reactions' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'Category không tồn tại.',
            'provider_service_id.exists' => 'Provider service không tồn tại.',
            'name.max' => 'Tên không được vượt quá 255 ký tự.',
            'sell_rate.numeric' => 'Giá bán phải là số.',
            'sell_rate.min' => 'Giá bán phải lớn hơn hoặc bằng 0.',
            'min_quantity.integer' => 'Số lượng tối thiểu phải là số nguyên.',
            'min_quantity.min' => 'Số lượng tối thiểu phải lớn hơn hoặc bằng 1.',
            'max_quantity.integer' => 'Số lượng tối đa phải là số nguyên.',
            'max_quantity.min' => 'Số lượng tối đa phải lớn hơn hoặc bằng 1.',
            'is_active.boolean' => 'Trạng thái phải là true hoặc false.',
        ];
    }
}
