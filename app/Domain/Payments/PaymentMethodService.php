<?php

namespace App\Domain\Payments;

use App\Domain\Shared\AuditDiff;
use App\Domain\Shared\AuditLogger;
use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment method settings (FR-036, FR-101, D-097): rename, turn on/off and
 * whether a reference number is required. Codes and types are fixed because
 * checkout and the drawer count depend on them. At least one method stays
 * active so the POS can always take payment.
 */
class PaymentMethodService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, is_active: bool, requires_reference: bool}  $data
     */
    public function update(User $actor, PaymentMethod $method, array $data): PaymentMethod
    {
        return DB::transaction(function () use ($actor, $method, $data) {
            /** @var PaymentMethod $method */
            $method = PaymentMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();

            if (! $data['is_active'] && $method->is_active
                && ! PaymentMethod::query()->where('is_active', true)->whereKeyNot($method->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['is_active' => __('Keep at least one payment method active.')]);
            }

            if ($method->type === PaymentMethodType::Cash && $data['requires_reference']) {
                throw ValidationException::withMessages(['requires_reference' => __('Cash payments have no reference number.')]);
            }

            $before = $method->only(['name', 'is_active', 'requires_reference']);
            $method->forceFill($data)->save();

            [$old, $new] = AuditDiff::between($before, $method->only(['name', 'is_active', 'requires_reference']));
            if ($new !== []) {
                $this->audit->record('payment_method.updated', $method, $old, $new, null, $actor);
            }

            return $method;
        });
    }
}
