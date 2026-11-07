<?php

namespace Tests\Concerns;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\PriceService;
use App\Domain\Shared\BusinessClock;
use App\Domain\Shared\Money;
use App\Domain\Shared\NumberSequence;
use App\Domain\Shifts\ShiftService;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\RefundKind;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds a sellable catalog and a cashier with an open shift, and posts
 * checkouts the way the POS does.
 */
trait InteractsWithCheckout
{
    use InteractsWithStaff;
    use InteractsWithStock;

    protected User $owner;

    protected function setUpCheckout(): void
    {
        $this->seedAccess();
        $this->seed(PaymentMethodSeeder::class);
        $this->owner = $this->staff(PermissionCatalog::OWNER);
    }

    protected function cashierOnShift(?Branch $location = null, string $openingCash = '1000.00', string $role = PermissionCatalog::CASHIER): User
    {
        $location ??= $this->mainLocation();
        $terminal = Terminal::query()->firstOrCreate(
            ['branch_id' => $location->id, 'terminal_code' => 'POS-1'],
            ['name' => 'Counter', 'is_active' => true],
        );
        $cashier = $this->staff($role, $location);
        app(ShiftService::class)->open($cashier, $terminal, Money::fromDecimal($openingCash));

        return $cashier;
    }

    protected function shiftOf(User $cashier): CashierShift
    {
        return CashierShift::query()->where('user_id', $cashier->id)->latest('id')->firstOrFail();
    }

    /** A doughnut sold at the location, with stock on hand. */
    protected function doughnut(string $price = '44.00', string $stock = '10', ?Branch $location = null, string $name = 'Choco Custard'): Product
    {
        $location ??= $this->mainLocation();
        $product = Product::factory()->finishedStock()->create(['name' => $name]);
        $this->sellAt($product, $location, $price);

        if ($stock !== '0') {
            /** @var InventoryItem $item */
            $item = $product->inventoryItem;
            $this->postStock($this->owner, $location, $item, $stock);
        }

        return $product;
    }

    /**
     * A made-to-order coffee with two sizes.
     *
     * @return array{product: Product, iced: ProductVariant, hot: ProductVariant}
     */
    protected function coffee(): array
    {
        $product = Product::factory()->create(['name' => 'Latte', 'sku' => 'CF-LATTE']);
        $iced = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Iced 16oz', 'sku' => 'CF-LATTE-ICED', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]);
        $hot = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Hot 12oz', 'sku' => 'CF-LATTE-HOT', 'is_default' => false, 'is_active' => true, 'sort_order' => 1]);
        $this->sellAt($product, $this->mainLocation(), '110.00');
        app(PriceService::class)->set($this->owner, $product, '120.00', $hot);

        return compact('product', 'iced', 'hot');
    }

    protected function sellAt(Product $product, Branch $location, string $price): void
    {
        $product->locations()->syncWithoutDetaching([$location->id => ['is_available' => true]]);
        if (! $product->prices()->whereNull('variant_id')->whereNull('branch_id')->exists()) {
            app(PriceService::class)->set($this->owner, $product, $price);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function payload(array $items, array $payment = ['method' => 'CASH', 'amount_tendered' => '1000'], array $extra = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'order_type' => 'walk_in',
            'items' => $items,
            'payment' => $payment,
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function checkout(User $cashier, array $payload): TestResponse
    {
        return $this->actingAs($cashier)->post('/pos/checkout', $payload);
    }

    /**
     * Writes a cancellation the way the removed Cancel action did (before
     * D-169), so reports and refunds can be tested against older orders:
     * the order is cancelled, its payment refunded, and one void record
     * covers every item. Stock is not touched.
     */
    protected function pastCancellation(Order $order, User $by, string $reason): Refund
    {
        /** @var Payment $payment */
        $payment = $order->payments()->with('method')->firstOrFail();
        /** @var Branch $location */
        $location = $order->branch()->firstOrFail();
        $today = BusinessClock::today();
        $isCash = $payment->method?->type === PaymentMethodType::Cash;

        $payment->forceFill(['status' => PaymentStatus::Refunded])->save();
        $refund = new Refund;
        $refund->forceFill([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'branch_id' => $order->branch_id,
            'cashier_shift_id' => $isCash ? $order->cashier_shift_id : null,
            'refund_number' => sprintf('%s-%s-R%04d', $location->code, $today->format('Ymd'), app(NumberSequence::class)->next($location, 'refund', $today)),
            'kind' => RefundKind::Void,
            'amount' => $payment->amount,
            'refund_method' => (string) $payment->method?->code,
            'reason' => $reason,
            'status' => Refund::PROCESSED,
            'requested_by' => $by->id,
            'approved_by' => $by->id,
            'processed_at' => now(),
        ])->save();
        $order->items()->each(fn (OrderItem $item) => (new RefundItem)->forceFill([
            'refund_id' => $refund->id,
            'order_item_id' => $item->id,
            'quantity' => $item->quantity,
            'amount' => Money::fromDecimal($item->line_total)->subtract(Money::fromDecimal($item->discount_amount))->toDecimal(),
            'return_to_stock' => false,
        ])->save());

        $order->forceFill([
            'status' => OrderStatus::Cancelled,
            'payment_status' => OrderPaymentStatus::Refunded,
            'cancelled_at' => now(),
            'cancelled_by' => $by->id,
            'cancellation_reason' => $reason,
        ])->save();

        return $refund;
    }
}
