<?php

namespace App\Http\Requests\Admin;

use App\Enums\InventoryTrackingMode;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Product create/update (UC-002, FR-016–FR-018). */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => strtoupper(trim((string) $this->input('sku'))),
            'variants' => array_values((array) $this->input('variants', [])),
            'location_ids' => array_values((array) $this->input('location_ids', [])),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'sku' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique('products', 'sku')->ignore($product?->id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'product_type' => ['required', Rule::enum(ProductType::class)],
            'inventory_tracking_mode' => ['required', Rule::enum(InventoryTrackingMode::class)],
            'base_unit' => ['nullable', 'string', 'max:30'],
            'is_available_for_pos' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_image' => ['sometimes', 'boolean'],
            'variants' => ['array', 'max:20'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'variants.*.sku' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]*$/', 'distinct:ignore_case'],
            'variants.*.is_default' => ['sometimes', 'boolean'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'location_ids' => ['array'],
            'location_ids.*' => ['integer', 'distinct', 'exists:branches,id'],
            'base_price' => $product === null ? ['required', 'string', 'max:12'] : ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Product|null $product */
            $product = $this->route('product');

            foreach ((array) $this->input('variants', []) as $i => $row) {
                $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
                if ($sku === '') {
                    continue;
                }
                $taken = ProductVariant::query()->where('sku', $sku)
                    ->when(isset($row['id']), fn ($q) => $q->whereKeyNot((int) $row['id']))
                    ->exists();
                if ($taken || $sku === $this->input('sku')) {
                    $validator->errors()->add("variants.{$i}.sku", __('This SKU is already used.'));
                }
            }

            if ($product !== null) {
                $own = $product->variants()->pluck('id')->all();
                $missing = array_diff($own, array_filter(array_map(fn ($r) => isset($r['id']) ? (int) $r['id'] : null, (array) $this->input('variants', []))));
                if ($missing !== []) {
                    $validator->errors()->add('variants', __('Variants cannot be removed; mark them inactive instead.'));
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.regex' => __('Use letters, numbers and hyphens, e.g. DN-GLAZED.'),
            'sku.unique' => __('Another product already uses this SKU.'),
            'base_price.required' => __('Enter the selling price.'),
        ];
    }
}
