<?php

namespace App\Domain\Refunds;

use App\Domain\Settings\StoreSettings;
use App\Domain\Shared\Money;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Who may give money back (FR-042, FR-044; D-152). Someone with
 * `refunds.request` starts the refund. It goes through on their own when
 * they hold `refunds.approve`, or when the amount is no more than Store
 * settings › Refunds without approval up to (₱0.00 by default, so every
 * refund is approved). Otherwise a manager working at the location types
 * their username and password at the counter; five wrong tries a minute
 * lock that counter out of refund approvals for a minute.
 */
class RefundApproval
{
    public const ATTEMPTS = 5;

    public function __construct(private readonly StoreSettings $settings) {}

    public function needsApproval(User $actor, Money $amount): bool
    {
        return ! $actor->can('refunds.approve')
            && $amount->greaterThan($this->settings->money('refund.approval_over'));
    }

    /** The approving user: the actor themself when they hold the permission, null when none was needed. */
    public function resolve(User $actor, Branch $location, Money $amount, RefundRequest $request): ?User
    {
        if ($actor->can('refunds.approve')) {
            return $actor;
        }
        if (! $this->needsApproval($actor, $amount)) {
            return null;
        }

        $username = trim((string) $request->approverUsername);
        $password = (string) $request->approverPassword;
        if ($username === '' || $password === '') {
            throw ValidationException::withMessages(['approver' => __('A manager must approve this refund.')]);
        }

        $key = "refund-approval:{$actor->id}";
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw ValidationException::withMessages(['approver' => __('Too many tries. Wait :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $approver = User::query()->where('username', $username)->where('is_active', true)->first();
        $ok = $approver !== null
            && Hash::check($password, $approver->password)
            && $approver->id !== $actor->id
            && $approver->can('refunds.approve')
            && $approver->worksAt($location->id);

        if (! $ok) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['approver' => __('That manager cannot approve refunds here, or the password is wrong.')]);
        }
        RateLimiter::clear($key);

        /** @var User $approver */
        return $approver;
    }
}
