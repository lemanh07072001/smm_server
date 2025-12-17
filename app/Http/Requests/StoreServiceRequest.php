<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
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
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'platform_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'provider_service_id' => ['required', 'integer', 'exists:provider_services,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sell_rate' => ['required', 'numeric', 'min:0'],
            'min_quantity' => ['required', 'integer', 'min:1'],
            'max_quantity' => ['required', 'integer', 'min:1', 'gte:min_quantity'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
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
            'category_id.required' => 'Category là bắt buộc.',
            'category_id.exists' => 'Category không tồn tại.',
            'provider_service_id.required' => 'Provider service là bắt buộc.',
            'provider_service_id.exists' => 'Provider service không tồn tại.',
            'name.required' => 'Tên là bắt buộc.',
            'name.max' => 'Tên không được vượt quá 255 ký tự.',
            'sell_rate.required' => 'Giá bán là bắt buộc.',
            'sell_rate.numeric' => 'Giá bán phải là số.',
            'sell_rate.min' => 'Giá bán phải lớn hơn hoặc bằng 0.',
            'min_quantity.required' => 'Số lượng tối thiểu là bắt buộc.',
            'min_quantity.integer' => 'Số lượng tối thiểu phải là số nguyên.',
            'min_quantity.min' => 'Số lượng tối thiểu phải lớn hơn hoặc bằng 1.',
            'max_quantity.required' => 'Số lượng tối đa là bắt buộc.',
            'max_quantity.integer' => 'Số lượng tối đa phải là số nguyên.',
            'max_quantity.min' => 'Số lượng tối đa phải lớn hơn hoặc bằng 1.',
            'max_quantity.gte' => 'Số lượng tối đa phải lớn hơn hoặc bằng số lượng tối thiểu.',
            'is_active.boolean' => 'Trạng thái phải là true hoặc false.',
        ];
    }
}
