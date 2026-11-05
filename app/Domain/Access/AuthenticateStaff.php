<?php

namespace App\Domain\Access;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fortify authentication callback (FR-001, FR-002, FR-008; UC-001 A1/A2).
 *
 * - Accepts email OR username in the `login` field.
 * - Unknown account / wrong password: returns null, so Fortify shows the
 *   generic "credentials do not match" message and fires the Failed event.
 * - Correct password but deactivated account: specific message. Revealed
 *   only after a correct password, so it does not disclose account existence.
 */
class AuthenticateStaff
{
    /** Hash of a random string, checked when no user matches to even out response timing. */
    private static ?string $timingHash = null;

    public function __invoke(Request $request): ?User
    {
        $identifier = trim((string) $request->input('login'));
        $password = (string) $request->input('password');

        $user = $identifier === '' ? null : User::query()
            ->where(fn ($query) => $query->where('email', $identifier)->orWhere('username', $identifier))
            ->first();

        if ($user === null) {
            Hash::check($password, self::$timingHash ??= Hash::make(Str::random(40)));

            return null;
        }

        if (! Hash::check($password, $user->password)) {
            return null;
        }

        if (! $user->canSignIn()) {
            LoginEvent::query()->create([
                'user_id' => $user->id,
                'identifier' => mb_substr($identifier, 0, 150),
                'succeeded' => false,
                'reason' => LoginEvent::REASON_INACTIVE,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            throw ValidationException::withMessages([
                'login' => [__('This account is inactive. Please contact your manager.')],
            ]);
        }

        return $user;
    }
}
