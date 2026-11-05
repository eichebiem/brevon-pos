<?php

namespace App\Listeners;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Records successful, failed and throttled login attempts (FR-009, D-020).
 * Passwords are never stored.
 */
class RecordLoginEvent
{
    public function __construct(private readonly Request $request) {}

    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->record($user->id, $user->username ?? $user->email, true, null);
    }

    public function handleFailed(Failed $event): void
    {
        $identifier = (string) ($event->credentials['login'] ?? $event->credentials['email'] ?? '');

        $this->record($this->lookupUserId($identifier), $identifier, false, LoginEvent::REASON_INVALID_CREDENTIALS);
    }

    public function handleLockout(Lockout $event): void
    {
        $identifier = (string) $event->request->input('login', '');

        $this->record($this->lookupUserId($identifier), $identifier, false, LoginEvent::REASON_THROTTLED);
    }

    private function record(?int $userId, string $identifier, bool $succeeded, ?string $reason): void
    {
        LoginEvent::query()->create([
            'user_id' => $userId,
            'identifier' => mb_substr(trim($identifier), 0, 150),
            'succeeded' => $succeeded,
            'reason' => $reason,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }

    private function lookupUserId(string $identifier): ?int
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $id = User::withTrashed()
            ->where(fn ($query) => $query->where('email', $identifier)->orWhere('username', $identifier))
            ->value('id');

        return is_int($id) ? $id : null;
    }
}
