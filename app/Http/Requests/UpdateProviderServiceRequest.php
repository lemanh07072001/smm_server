<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProviderServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'provider_service_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('provider_services')->where(function ($query) {
                    return $query->where('provider_id', $this->provider_id);
                })->ignore($id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category_name' => ['nullable', 'string', 'max:100'],
            'cost_rate' => ['required', 'numeric', 'min:0'],
            'min_quantity' => ['required', 'integer', 'min:1'],
            'max_quantity' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
            'reaction_types' => ['nullable', 'array'],
            'reaction_types.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider_id.required' => 'Provider là bắt buộc.',
            'provider_id.exists' => 'Provider không tồn tại.',
            'provider_service_code.required' => 'Mã dịch vụ là bắt buộc.',
            'provider_service_code.max' => 'Mã dịch vụ không được vượt quá 50 ký tự.',
            'provider_service_code.unique' => 'Mã dịch vụ đã tồn tại cho provider này.',
            'name.required' => 'Tên dịch vụ là bắt buộc.',
            'name.max' => 'Tên dịch vụ không được vượt quá 255 ký tự.',
            'cost_rate.required' => 'Giá mua là bắt buộc.',
            'cost_rate.numeric' => 'Giá mua phải là số.',
            'cost_rate.min' => 'Giá mua phải lớn hơn hoặc bằng 0.',
            'min_quantity.required' => 'Số lượng tối thiểu là bắt buộc.',
            'min_quantity.integer' => 'Số lượng tối thiểu phải là số nguyên.',
            'max_quantity.required' => 'Số lượng tối đa là bắt buộc.',
            'max_quantity.integer' => 'Số lượng tối đa phải là số nguyên.',
            'is_active.required' => 'Trạng thái là bắt buộc.',
        ];
    }
}
