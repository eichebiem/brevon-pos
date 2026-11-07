<?php

namespace App\Domain\Sales;

use App\Domain\Discounts\AppliedDiscount;
use App\Domain\Discounts\DiscountApproval;
use App\Domain\Discounts\DiscountCalculator;
use App\Domain\Discounts\DiscountRequest;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryLedger;
use App\Domain\Inventory\StockChange;
use App\Domain\Production\RecipeBook;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\Money;
use App\Domain\Shared\NumberSequence;
use App\Domain\Shared\Quantity;
use App\Enums\BeneficiaryType;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryTrackingMode;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderDiscountBeneficiary;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Completes a sale in one database transaction (FR-032; prompt invariants
 * 1–6, 9–11): the order, its lines, status history, payment,
 * stock deduction and the shift link either all commit or none do.
 *
 * Location, terminal and shift come from the cashier's open shift (FR-012,
 * FR-106, D-060), and the location's operating period is re-checked inside
 * the transaction (FR-107). Rows are locked in one fixed order — shift,
 * payment method (GCash reference check), discount (when it has a use
 * limit), number sequence, stock balances —
 * so concurrent checkouts queue instead of deadlocking.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly NumberSequence $numbers,
        private readonly InventoryLedger $ledger,
        private readonly AuditLogger $audit,
        private readonly RecipeBook $recipes,
        private readonly DiscountCalculator $discounts,
        private readonly DiscountApproval $approvals,
    ) {}

    public function checkout(User $cashier, CheckoutData $data): CheckoutResult
    {
        $hash = $data->requestHash();

        // A retry of a sale that already went through returns that sale,
        // even if the shift has closed or stock has run out since (UC-003 A4).
        if ($existing = $this->existing($cashier, $data->idempotencyKey, $hash)) {
            return new CheckoutResult($existing, true);
        }

        try {
            return DB::transaction(fn () => $this->complete($cashier, $data, $hash), attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // Two copies of the same request raced; the unique key let only one in.
            if ($existing = $this->existing($cashier, $data->idempotencyKey, $hash)) {
                return new CheckoutResult($existing, true);
            }

            // The database refused a reference number that is already recorded.
            if (str_contains($e->getMessage(), 'payments_method_reference_unique')) {
                throw ValidationException::withMessages(['payment.reference' => __('This reference number was already used for another order.')]);
            }

            throw $e;
        }
    }

    private function complete(User $cashier, CheckoutData $data, string $hash): CheckoutResult
    {
        $shift = $this->lockOpenShift($cashier);
        /** @var Branch $location */
        $location = $shift->branch()->firstOrFail();
        $today = BusinessClock::today();

        if (! $location->acceptsNewOperationsOn($today)) {
            throw ValidationException::withMessages(['items' => __(':location is not open for sales today. Close the shift.', ['location' => $location->name])]);
        }

        // Re-check under the shift lock: a duplicate request from the same
        // cashier waits on that lock and then sees the first one's order.
        // Plain read on purpose: a locking read that finds nothing takes a
        // gap lock, which deadlocked concurrent checkouts (D-106). The
        // unique (branch, key) index remains the final guard.
        $existing = Order::query()->where('branch_id', $location->id)->where('idempotency_key', $data->idempotencyKey)->first();
        if ($existing !== null) {
            return new CheckoutResult($this->replay($existing, $hash), true);
        }

        $cart = $this->pricer->price($location, $data->lines, $data->discount);
        $beneficiary = $cart->discount?->discount->isSeniorPwd() ? $this->discounts->beneficiary($data->discount?->beneficiary) : null;

        if ($data->expectedTotal !== null && ! $data->expectedTotal->equals($cart->grandTotal)) {
            throw ValidationException::withMessages(['expected_total' => __('Prices changed: the total is now :total. Review the order and try again.', ['total' => $cart->grandTotal->format()])]);
        }

        if (! $cart->grandTotal->isPositive()) {
            throw ValidationException::withMessages(['items' => __('The order total must be more than zero.')]);
        }

        $method = $this->lockMethod($data->payment);
        $payment = $this->preparePayment($cashier, $method, $data->payment, $cart->grandTotal);
        $approver = $cart->discount === null || $data->discount === null ? null : $this->authoriseDiscount($cashier, $location, $cart->discount, $data->discount);

        $number = sprintf('%s-%s-%04d', $location->code, $today->format('Ymd'), $this->numbers->next($location, 'order', $today));
        $now = now();

        $order = new Order;
        $order->forceFill([
            'branch_id' => $location->id,
            'terminal_id' => $shift->terminal_id,
            'cashier_shift_id' => $shift->id,
            'order_number' => $number,
            'business_date' => $today->toDateString(),
            'order_type' => $data->orderType,
            // D-169: every sale is finished at the counter.
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Paid,
            'subtotal' => $cart->subtotal->toDecimal(),
            'discount_total' => $cart->discountTotal->toDecimal(),
            'service_charge' => '0.00',
            'grand_total' => $cart->grandTotal->toDecimal(),
            'amount_paid' => $cart->grandTotal->toDecimal(),
            'amount_due' => '0.00',
            'notes' => $data->notes,
            'created_by' => $cashier->id,
            'completed_at' => $now,
            'idempotency_key' => $data->idempotencyKey,
            'idempotency_request_hash' => $hash,
        ])->save();

        $this->saveLines($order, $cart);
        $this->saveDiscount($order, $cart, $cashier, $approver, $beneficiary, $data->discount?->reason);
        $this->recordStatus($order, null, OrderStatus::Completed, $cashier);

        $record = new Payment;
        $record->forceFill([...$payment, 'order_id' => $order->id, 'payment_method_id' => $method->id, 'recorded_by' => $cashier->id, 'paid_at' => $now])->save();

        $this->deductStock($cashier, $location, $order, $cart);

        $this->audit->record('order.created', $order, null, [
            'order_number' => $order->order_number,
            'order_type' => $data->orderType->value,
            'status' => OrderStatus::Completed->value,
            'grand_total' => $order->grand_total,
            'lines' => count($cart->lines),
            'shift' => $shift->shift_number,
        ], $location->id, $cashier);
        $this->audit->record('payment.recorded', $record, null, [
            'order_number' => $order->order_number,
            'method' => $method->code,
            'amount' => $record->amount,
            'amount_tendered' => $record->amount_tendered,
            'change_amount' => $record->change_amount,
            'reference_number' => $record->reference_number,
            'status' => $record->status->value,
        ], $location->id, $cashier);

        return new CheckoutResult($order, false);
    }

    private function existing(User $cashier, string $key, string $hash): ?Order
    {
        $order = Order::query()->where('created_by', $cashier->id)->where('idempotency_key', $key)->first();

        return $order === null ? null : $this->replay($order, $hash);
    }

    private function replay(Order $order, string $hash): Order
    {
        if (! hash_equals((string) $order->idempotency_request_hash, $hash)) {
            throw new IdempotencyConflict($order->order_number);
        }

        return $order;
    }

    /** BR-017, D-041: every POS sale belongs to the cashier's open shift, which also fixes the location. */
    private function lockOpenShift(User $cashier): CashierShift
    {
        $shift = CashierShift::query()->open()->where('user_id', $cashier->id)->lockForUpdate()->first();

        if ($shift === null) {
            throw ValidationException::withMessages(['shift' => __('Open a shift before taking payments.')]);
        }

        return $shift;
    }

    private function lockMethod(PaymentInput $input): PaymentMethod
    {
        // Locked so two GCash payments with the same reference cannot both pass the duplicate check.
        $method = PaymentMethod::query()->where('code', $input->methodCode)->lockForUpdate()->first();

        if ($method === null || ! $method->is_active) {
            throw ValidationException::withMessages(['payment.method' => __('This payment method is not available.')]);
        }

        return $method;
    }

    /**
     * @return array{amount: string, amount_tendered: string|null, change_amount: string, reference_number: string|null, status: PaymentStatus, verified_by: int|null, verified_at: CarbonImmutable|null}
     */
    private function preparePayment(User $cashier, PaymentMethod $method, PaymentInput $input, Money $due): array
    {
        if ($method->type === PaymentMethodType::Cash) {
            // D-038, BR-006: the payment is the amount due; change is handed back, never revenue.
            if ($input->amountTendered === null || $input->amountTendered->lessThan($due)) {
                throw ValidationException::withMessages(['payment.amount_tendered' => __('Cash received must be at least :due.', ['due' => $due->format()])]);
            }

            return [
                'amount' => $due->toDecimal(),
                'amount_tendered' => $input->amountTendered->toDecimal(),
                'change_amount' => $input->amountTendered->subtract($due)->toDecimal(),
                'reference_number' => null,
                'status' => PaymentStatus::Verified,
                'verified_by' => null,
                'verified_at' => null,
            ];
        }

        $reference = $input->reference === null ? null : strtoupper(trim($input->reference));
        if ($method->requires_reference) {
            if ($reference === null || preg_match('/^[A-Z0-9]{6,40}$/', $reference) !== 1) {
                throw ValidationException::withMessages(['payment.reference' => __('Enter the :method reference number (6–40 letters or digits).', ['method' => $method->name])]);
            }

            if ($this->referenceUsed($method, $reference)) {
                throw ValidationException::withMessages(['payment.reference' => __('This :method reference number was already used for another order.', ['method' => $method->name])]);
            }
        }

        // D-168: the cashier confirms the transfer arrived before finishing the sale; there is no later check.
        return [
            'amount' => $due->toDecimal(),
            'amount_tendered' => null,
            'change_amount' => '0.00',
            'reference_number' => $reference,
            'status' => PaymentStatus::Verified,
            'verified_by' => $cashier->id,
            'verified_at' => CarbonImmutable::now(),
        ];
    }

    /** D-109: a reference number is used once per method, at any location; the database enforces it too. */
    private function referenceUsed(PaymentMethod $method, string $reference): bool
    {
        return Payment::query()->where('payment_method_id', $method->id)->where('reference_number', $reference)->exists();
    }

    private function saveLines(Order $order, PricedCart $cart): void
    {
        foreach ($cart->lines as $i => $line) {
            $item = new OrderItem;
            $item->forceFill([
                'order_id' => $order->id,
                'product_id' => $line->product->id,
                'variant_id' => $line->variant?->id,
                'sku_snapshot' => $line->variant->sku ?? $line->product->sku,
                'product_name_snapshot' => $line->product->name,
                'variant_name_snapshot' => $line->variant?->name,
                'product_type_snapshot' => $line->product->product_type->value,
                'quantity' => $line->quantity->toString(),
                'unit_price' => $line->unitPrice->toDecimal(),
                'discount_amount' => ($cart->lineDiscounts[$i] ?? Money::zero())->toDecimal(),
                'line_total' => $line->lineTotal->toDecimal(),
                'notes' => $line->notes,
            ])->save();
        }
    }

    /**
     * D-145, D-146: a limited discount is locked (after the payment method,
     * before the number sequence) and its uses counted again, then the
     * approval is checked.
     */
    private function authoriseDiscount(User $cashier, Branch $location, AppliedDiscount $applied, DiscountRequest $request): ?User
    {
        if ($applied->discount->max_uses !== null) {
            $locked = Discount::query()->whereKey($applied->discount->id)->lockForUpdate()->firstOrFail();
            if ($this->discounts->usedUp($locked)) {
                throw ValidationException::withMessages(['discount' => __(':name has been used up.', ['name' => $locked->name])]);
            }
        }

        return $this->approvals->resolve($cashier, $location, $applied->discount, $applied->amount, $request);
    }

    /**
     * FR-084, D-148: what was given, by whom and who approved it; SC/PWD
     * details go only on their own encrypted row. The audit entry carries the
     * masked details.
     *
     * @param  array{type: BeneficiaryType, full_name: string, card_number: string, address: string}|null  $beneficiary
     */
    private function saveDiscount(Order $order, PricedCart $cart, User $cashier, ?User $approver, ?array $beneficiary, ?string $reason): void
    {
        $applied = $cart->discount;
        if ($applied === null) {
            return;
        }
        $discount = $applied->discount;
        $row = new OrderDiscount;
        $row->forceFill([
            'order_id' => $order->id,
            'discount_id' => $discount->id,
            'discount_name_snapshot' => $discount->name,
            'discount_code_snapshot' => $discount->code,
            'discount_type_snapshot' => $discount->discount_type,
            'discount_value_snapshot' => $discount->value,
            'discount_amount' => $applied->amount->toDecimal(),
            'reason' => $reason,
            'applied_by' => $cashier->id,
            'approved_by' => $approver?->id,
        ])->save();

        $masked = null;
        if ($beneficiary !== null) {
            $person = new OrderDiscountBeneficiary;
            $person->forceFill([
                'order_discount_id' => $row->id,
                'beneficiary_type' => $beneficiary['type'],
                'full_name' => $beneficiary['full_name'],
                'card_number' => $beneficiary['card_number'],
                'address' => $beneficiary['address'],
            ])->save();
            $masked = ['type' => $beneficiary['type']->value, 'name' => $person->maskedName(), 'card_number' => $person->maskedCard()];
        }

        $this->audit->record('discount.applied', $row, null, [
            'order_number' => $order->order_number,
            'discount' => $discount->name,
            'code' => $discount->code,
            'amount' => $row->discount_amount,
            'lines' => count($applied->lineIndexes),
            'approved_by' => $approver?->username,
            'beneficiary' => $masked,
        ], $order->branch_id, $cashier);
    }

    private function recordStatus(Order $order, ?OrderStatus $from, OrderStatus $to, User $actor): void
    {
        $history = new OrderStatusHistory;
        $history->forceFill(['order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'changed_by' => $actor->id, 'created_at' => now()])->save();
    }

    /**
     * BR-009, D-046: finished-stock lines reduce their stock item now.
     * Recipe-at-sale lines deduct their recipe's ingredients for the size
     * sold (FR-072, D-141); a drink with no recipe yet deducts nothing.
     * Untracked lines have no stock effect. Ingredients of finished goods
     * were deducted at production, never again here (FR-071).
     */
    private function deductStock(User $cashier, Branch $location, Order $order, PricedCart $cart): void
    {
        /** @var array<int, Quantity> $perItem */
        $perItem = [];
        foreach ($cart->lines as $line) {
            if ($line->product->inventory_tracking_mode === InventoryTrackingMode::RecipeAtSale) {
                $recipe = $this->recipes->active($line->product, $line->variant);
                foreach ($recipe ? $this->recipes->ingredientsFor($recipe, $line->quantity) : [] as $itemId => $quantity) {
                    $perItem[$itemId] = ($perItem[$itemId] ?? Quantity::zero())->add($quantity);
                }

                continue;
            }
            if ($line->product->inventory_tracking_mode !== InventoryTrackingMode::FinishedStock) {
                continue;
            }
            $itemId = (int) $line->stockItemId();
            $perItem[$itemId] = ($perItem[$itemId] ?? Quantity::zero())->add($line->quantity);
        }
        ksort($perItem); // balance rows are locked in item order anyway

        $changes = [];
        foreach ($perItem as $itemId => $quantity) {
            $changes[] = new StockChange($location->id, $itemId, InventoryMovementType::Sale, $quantity, reference: $order);
        }

        try {
            $this->ledger->post($cashier, $changes);
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages(['items' => __('Not enough :item in stock: :available left, :requested in this order.', [
                'item' => $e->itemName,
                'available' => rtrim(rtrim($e->available, '0'), '.') ?: '0',
                'requested' => rtrim(rtrim($e->requested, '0'), '.'),
            ])]);
        }
    }
}
