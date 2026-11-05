<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Pop-up create / location edit (FR-011, FR-104, D-065). Route middleware: locations.manage. */
class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // The code is assigned by the app and never changes (D-121), so it is not accepted here.
        return [
            'name' => ['required', 'string', 'max:150'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:100'],
            'city_municipality' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'operating_starts_on' => ['nullable', 'date_format:Y-m-d'],
            'operating_ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:operating_starts_on'],
            'copy_availability' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => __('Use capital letters, numbers and hyphens, e.g. POP-SMR1.'),
            'operating_ends_on.after_or_equal' => __('The last day cannot be before the first day.'),
        ];
    }
}
