<?php

namespace App\Domain\Locations;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * The operating location the signed-in user is working at in admin screens
 * (D-023). Only active locations assigned through user_branch qualify.
 * POS/shift work will derive the location from the open shift instead (D-060).
 */
class LocationContext
{
    public const SESSION_KEY = 'brevon.location_id';

    public function __construct(private readonly Session $session) {}

    /**
     * @return Collection<int, Branch>
     */
    public function available(User $user): Collection
    {
        return $user->branches()
            ->where('branches.is_active', true)
            ->orderByDesc('user_branch.is_default')
            ->orderBy('branches.location_type')
            ->orderBy('branches.name')
            ->get();
    }

    public function current(User $user): ?Branch
    {
        $available = $this->available($user);
        $selectedId = $this->session->get(self::SESSION_KEY);

        $current = $available->firstWhere('id', $selectedId) ?? $available->first();

        if ($current?->id !== $selectedId) {
            $current === null
                ? $this->session->forget(self::SESSION_KEY)
                : $this->session->put(self::SESSION_KEY, $current->id);
        }

        return $current;
    }

    public function canUse(User $user, Branch $branch): bool
    {
        return $this->available($user)->contains('id', $branch->id);
    }

    public function switchTo(User $user, Branch $branch): bool
    {
        if (! $this->canUse($user, $branch)) {
            return false;
        }

        $this->session->put(self::SESSION_KEY, $branch->id);

        return true;
    }
}
