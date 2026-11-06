<?php

namespace App\Domain\Discounts;

use App\Domain\Settings\StoreSettings;
use App\Domain\Shared\Money;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Who may give a discount (FR-044; D-146). A cashier with
 * `pos.apply-discount` gives a discount on their own unless the preset
 * needs approval or the amount is over Store settings › Approve discounts
 * over; then someone with `discounts.approve` must approve. A cashier who
 * holds `discounts.approve` approves their own. Otherwise a manager working
 * at the location types their username and password at the counter; five
 * wrong tries a minute lock the counter out of approvals for a minute.
 */
class DiscountApproval
{
    public const ATTEMPTS = 5;

    public function __construct(private readonly StoreSettings $settings) {}

    public function needsApproval(User $cashier, Discount $discount, Money $amount): bool
    {
        if ($cashier->can('discounts.approve')) {
            return false;
        }

        return $discount->requires_approval
            || $amount->greaterThan($this->settings->money('discount.approval_over'))
            || ! $cashier->can('pos.apply-discount');
    }

    /** The approving user, or null when none was needed. */
    public function resolve(User $cashier, Branch $location, Discount $discount, Money $amount, DiscountRequest $request): ?User
    {
        if ($cashier->can('discounts.approve')) {
            return $discount->requires_approval || $amount->greaterThan($this->settings->money('discount.approval_over')) ? $cashier : null;
        }
        if (! $this->needsApproval($cashier, $discount, $amount)) {
            return null;
        }

        $username = trim((string) $request->approverUsername);
        $password = (string) $request->approverPassword;
        if ($username === '' || $password === '') {
            throw ValidationException::withMessages(['discount.approver' => __('A manager must approve this discount.')]);
        }

        $key = "discount-approval:{$cashier->id}";
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw ValidationException::withMessages(['discount.approver' => __('Too many tries. Wait :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $approver = User::query()->where('username', $username)->where('is_active', true)->first();
        $ok = $approver !== null
            && Hash::check($password, $approver->password)
            && $approver->id !== $cashier->id
            && $approver->can('discounts.approve')
            && $approver->worksAt($location->id);

        if (! $ok) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['discount.approver' => __('That manager cannot approve discounts here, or the password is wrong.')]);
        }
        RateLimiter::clear($key);

        /** @var User $approver */
        return $approver;
    }
}
