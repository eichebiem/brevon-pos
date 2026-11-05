<?php

namespace App\Http\Middleware;

use App\Domain\Locations\LocationContext;
use App\Domain\Settings\StoreSettings;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => fn () => $this->authProps($request),
            'location' => fn () => $this->locationProps($request),
            'store' => fn () => [
                'name' => app(StoreSettings::class)->get('store.name'),
                'logo_url' => app(StoreSettings::class)->logoUrl(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Only explicit fields reach the browser (NFR-018).
     *
     * @return array<string, mixed>
     */
    private function authProps(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ['user' => null, 'roles' => [], 'permissions' => []];
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            ],
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locationProps(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $context = app(LocationContext::class);
        $current = $context->current($user);
        $present = fn (Branch $branch): array => [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'type' => $branch->location_type->value,
        ];

        return [
            'current' => $current ? $present($current) : null,
            'available' => $context->available($user)->map($present)->values()->all(),
        ];
    }
}
