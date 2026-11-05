<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Create/update a staff account (FR-005, NFR-004). Authorisation: route middleware users.manage. */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => $this->filled('username') ? strtolower(trim((string) $this->input('username'))) : null,
            'email' => strtolower(trim((string) $this->input('email'))),
            'is_active' => $this->boolean('is_active'),
            'employee' => $this->boolean('has_employee_profile') ? $this->input('employee') : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->route('user');
        $creating = $user === null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'confirmed', Password::default()],
            'is_active' => ['boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')],
            'default_branch_id' => ['required', 'integer', Rule::in($this->input('branch_ids', []))],
            'employee' => ['nullable', 'array'],
            'employee.employee_code' => ['required_with:employee', 'string', 'max:40', Rule::unique('employees', 'employee_code')->ignore($user?->employee_id)],
            'employee.first_name' => ['required_with:employee', 'string', 'max:100'],
            'employee.last_name' => ['required_with:employee', 'string', 'max:100'],
            'employee.middle_name' => ['nullable', 'string', 'max:100'],
            'employee.phone' => ['nullable', 'string', 'max:30'],
            'employee.hire_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_branch_id.in' => __('The default location must be one of the assigned locations.'),
            'branch_ids.required' => __('Assign at least one location.'),
            'roles.required' => __('Assign at least one role.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->safe()->only(['name', 'username', 'email', 'password', 'is_active', 'roles', 'branch_ids', 'default_branch_id', 'employee']);
        $data['employee'] ??= null;

        return $data;
    }
}
