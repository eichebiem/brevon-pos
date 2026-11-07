<?php

namespace App\Http\Requests\Pos;

use App\Domain\Discounts\BeneficiaryInput;
use App\Domain\Discounts\DiscountRequest;
use App\Domain\Sales\CartLine;
use App\Domain\Sales\CheckoutData;
use App\Domain\Sales\PaymentInput;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of a checkout or quote request. Only ids, quantities and choices
 * are accepted from the POS; every price comes from the server (BR-003).
 * Item notes are preparation instructions only — no customer details (D-069).
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware checks pos.create-sale / payments.record
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $money = ['decimal:0,2', 'min:0', 'max:9999999.99'];
        $quoteOnly = $this->routeIs('pos.quote');

        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'decimal:0,3', 'min:0.001', 'max:999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'order_type' => [$quoteOnly ? 'nullable' : 'required', Rule::enum(OrderType::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => [$quoteOnly ? 'nullable' : 'required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'expected_total' => ['nullable', ...$money],
            'payment' => [$quoteOnly ? 'nullable' : 'required', 'array'],
            'payment.method' => [$quoteOnly ? 'nullable' : 'required', 'string', 'max:30'],
            'payment.amount_tendered' => ['nullable', ...$money],
            'payment.reference' => ['nullable', 'string', 'max:60'],
            'discount' => ['nullable', 'array'],
            'discount.id' => ['required_with:discount', 'integer'],
            'discount.lines' => ['nullable', 'array', 'max:100'],
            'discount.lines.*' => ['integer', 'min:0'],
            'discount.reason' => ['nullable', 'string', 'max:255'],
            'discount.beneficiary' => ['nullable', 'array'],
            'discount.beneficiary.type' => ['nullable', 'string', 'max:20'],
            'discount.beneficiary.name' => ['nullable', 'string', 'max:150'],
            'discount.beneficiary.card_number' => ['nullable', 'string', 'max:40'],
            'discount.beneficiary.address' => ['nullable', 'string', 'max:255'],
            'discount.approver' => ['nullable', 'array'],
            'discount.approver.username' => ['nullable', 'string', 'max:100'],
            'discount.approver.password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<CartLine>
     */
    public function lines(): array
    {
        /** @var list<array{product_id: int|string, variant_id?: int|string|null, quantity: int|string|float, notes?: string|null}> $items */
        $items = array_values((array) $this->validated('items'));

        return array_map(fn (array $item) => new CartLine(
            productId: (int) $item['product_id'],
            variantId: isset($item['variant_id']) ? (int) $item['variant_id'] : null,
            quantity: Quantity::fromString((string) $item['quantity']),
            notes: self::clean($item['notes'] ?? null),
        ), $items);
    }

    /** The discount asked for, if any (D-146). Approver details are ignored by the quote. */
    public function discount(): ?DiscountRequest
    {
        $id = $this->validated('discount.id');
        if ($id === null) {
            return null;
        }
        $lines = $this->validated('discount.lines');
        $beneficiary = $this->validated('discount.beneficiary');

        return new DiscountRequest(
            discountId: (int) $id,
            lineIndexes: is_array($lines) ? array_values(array_map('intval', $lines)) : null,
            beneficiary: is_array($beneficiary) ? new BeneficiaryInput(
                type: self::clean($beneficiary['type'] ?? null),
                fullName: self::clean($beneficiary['name'] ?? null),
                cardNumber: self::clean($beneficiary['card_number'] ?? null),
                address: self::clean($beneficiary['address'] ?? null),
            ) : null,
            approverUsername: self::clean($this->validated('discount.approver.username')),
            approverPassword: is_string($password = $this->validated('discount.approver.password')) && $password !== '' ? $password : null,
            reason: self::clean($this->validated('discount.reason')),
        );
    }

    public function toCheckoutData(): CheckoutData
    {
        $tendered = $this->validated('payment.amount_tendered');
        $expected = $this->validated('expected_total');

        return new CheckoutData(
            idempotencyKey: (string) $this->validated('idempotency_key'),
            orderType: OrderType::from((string) $this->validated('order_type')),
            lines: $this->lines(),
            payment: new PaymentInput(
                methodCode: (string) $this->validated('payment.method'),
                amountTendered: $tendered === null ? null : Money::fromDecimal((string) $tendered),
                reference: self::clean($this->validated('payment.reference')),
            ),
            notes: self::clean($this->validated('notes')),
            expectedTotal: $expected === null ? null : Money::fromDecimal((string) $expected),
            discount: $this->discount(),
        );
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
