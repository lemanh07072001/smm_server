<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:100', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['nullable', 'integer', Rule::in(User::ROLES)],
            'balance' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
            'name.required' => 'Tên là bắt buộc.',
            'name.max' => 'Tên không được vượt quá 50 ký tự.',
            'email.required' => 'Email là bắt buộc.',
            'email.email' => 'Email không hợp lệ.',
            'email.unique' => 'Email đã tồn tại.',
            'password.required' => 'Mật khẩu là bắt buộc.',
            'password.min' => 'Mật khẩu phải có ít nhất 6 ký tự.',
            'role.in' => 'Role phải là 0 (admin), 1 (user), 2 (reseller), 3 (super_admin) hoặc 4 (tax).',
            'balance.numeric' => 'Số dư phải là số.',
            'balance.min' => 'Số dư phải lớn hơn hoặc bằng 0.',
            'discount.numeric' => 'Giảm giá phải là số.',
            'discount.min' => 'Giảm giá phải lớn hơn hoặc bằng 0.',
            'discount.max' => 'Giảm giá không được vượt quá 100%.',
            'is_active.boolean' => 'Trạng thái phải là true hoặc false.',
            'tax_percent.numeric' => 'Phần trăm thuế phải là số.',
            'tax_percent.min' => 'Phần trăm thuế không được nhỏ hơn 0.',
            'tax_percent.max' => 'Phần trăm thuế không được vượt quá 100.',
        ];
    }
}
