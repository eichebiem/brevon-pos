<?php

namespace App\Console\Commands;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Shared\AuditLogger;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off clean-up before the Phase 11a migrations (D-174). The GCash check
 * (D-168) and preparation (D-169) are gone, so their migrations refuse to run
 * while a GCash payment is still waiting or an order is still in progress.
 * This command settles them the way the old screens would have: the waiting
 * GCash payments are marked received, and paid orders still Pending, New,
 * Preparing or Ready are marked Completed. Every change is audited under the
 * person named in --user. Without --force it only lists what it would do.
 */
class FinishOpenOrdersCommand extends Command
{
    private const OPEN = ['pending', 'confirmed', 'preparing', 'ready'];

    protected $signature = 'brevon:finish-open-orders
        {--user= : Username of the Owner or Manager this is recorded under}
        {--force : Make the changes (without it, only list them)}';

    protected $description = 'Mark waiting GCash payments received and complete orders still in progress (before the Phase 11a migrations)';

    public function handle(AuditLogger $audit): int
    {
        $payments = Payment::query()->where('status', PaymentStatus::Pending)->with('order:id,order_number')->orderBy('id')->get();
        $orders = Order::query()->whereIn('status', self::OPEN)->orderBy('id')->get();

        if ($payments->isEmpty() && $orders->isEmpty()) {
            $this->info('Nothing to finish: no GCash payment is waiting and no order is in progress.');

            return self::SUCCESS;
        }

        $this->table(['Payment', 'Order', 'Amount', 'Reference', 'Change'], $payments->map(fn (Payment $p) => [
            $p->id, $p->order?->order_number, $p->amount, $p->reference_number, 'waiting -> received',
        ])->all());
        $waiting = array_values(array_map(fn (Payment $p) => $p->order_id, $payments->all()));
        $this->table(['Order', 'Status', 'Paid', 'Total', 'Change'], $orders->map(fn (Order $o) => [
            $o->order_number, $o->status->label(), $o->payment_status->value, $o->grand_total,
            $this->payable($o, $waiting) ? '-> Completed' : 'skipped (not paid)',
        ])->all());

        if (! $this->option('force')) {
            $this->line('Nothing changed. Run again with --user=<username> --force to make these changes.');

            return self::SUCCESS;
        }

        $actor = User::query()->where('username', (string) $this->option('user'))->first();
        if ($actor === null || ! $actor->is_active || ! $actor->hasAnyRole([PermissionCatalog::OWNER, PermissionCatalog::MANAGER])) {
            $this->error('Give --user=<username> of an active Owner or Manager; the changes are recorded under that person.');

            return self::FAILURE;
        }

        [$received, $completed, $skipped] = DB::transaction(function () use ($actor, $audit) {
            $received = 0;
            foreach (Payment::query()->where('status', PaymentStatus::Pending)->lockForUpdate()->get() as $payment) {
                $payment->forceFill(['status' => PaymentStatus::Verified, 'verified_by' => $actor->id, 'verified_at' => now()])->save();
                $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
                if ($order->payment_status === OrderPaymentStatus::PendingVerification) {
                    $order->forceFill(['payment_status' => OrderPaymentStatus::Paid])->save();
                }
                $audit->record('payment.verified', $payment, ['status' => PaymentStatus::Pending->value], [
                    'status' => PaymentStatus::Verified->value,
                    'order_number' => $order->order_number,
                    'reason' => 'Finished before the GCash check was removed (D-168)',
                ], $order->branch_id, $actor);
                $received++;
            }

            $completed = 0;
            $skipped = 0;
            foreach (Order::query()->whereIn('status', self::OPEN)->lockForUpdate()->get() as $order) {
                if ($order->payment_status !== OrderPaymentStatus::Paid) {
                    $skipped++;

                    continue;
                }
                $from = $order->status;
                $order->forceFill(['status' => OrderStatus::Completed, 'completed_at' => now()])->save();
                (new OrderStatusHistory)->forceFill([
                    'order_id' => $order->id, 'from_status' => $from, 'to_status' => OrderStatus::Completed,
                    'changed_by' => $actor->id, 'notes' => 'Finished before preparation was removed (D-169)', 'created_at' => now(),
                ])->save();
                $audit->record('order.completed', $order, ['status' => $from->value], [
                    'status' => OrderStatus::Completed->value,
                    'reason' => 'Finished before preparation was removed (D-169)',
                ], $order->branch_id, $actor);
                $completed++;
            }

            return [$received, $completed, $skipped];
        });

        $this->info("{$received} GCash payment(s) marked received, {$completed} order(s) completed.");
        if ($skipped > 0) {
            $this->warn("{$skipped} unpaid order(s) were left as they are; the preparation migration will still stop until they are dealt with.");

            return self::FAILURE;
        }
        $this->line('You can now run `php artisan migrate`.');

        return self::SUCCESS;
    }

    /**
     * Paid now, or paid once its waiting GCash payment is marked received.
     *
     * @param  list<int>  $waitingOrderIds
     */
    private function payable(Order $order, array $waitingOrderIds): bool
    {
        return $order->payment_status === OrderPaymentStatus::Paid
            || ($order->payment_status === OrderPaymentStatus::PendingVerification && in_array($order->id, $waitingOrderIds, true));
    }
}
