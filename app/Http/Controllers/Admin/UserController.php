<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\AccessGuard;
use App\Domain\Access\RoleOrder;
use App\Domain\Access\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Staff accounts (FR-005, FR-006). Route middleware: permission:users.manage. */
class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly AccessGuard $guard,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'in:active,inactive'],
            'location' => ['nullable', 'integer'],
        ]);

        $users = User::query()
            ->with(['roles:id,name,created_at', 'branches:id,code,name'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->role($role))
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($filters['location'] ?? null, fn ($query, $location) => $query->whereHas('branches', fn ($q) => $q->where('branches.id', $location)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => RoleOrder::sort($user->roles)->pluck('name'),
                'locations' => $user->branches->pluck('code'),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'filters' => (object) $filters,
            'roles' => RoleOrder::query()->pluck('name'),
            'locations' => Branch::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Form', [
            ...$this->formOptions(),
            'user' => null,
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $user = $this->users->create($actor, $request->payload());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account created for :name.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }

    public function edit(Request $request, User $user): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->guard->assertCanManageUser($actor, $user);

        return Inertia::render('admin/users/Form', [
            ...$this->formOptions(),
            'user' => [
                'id' => $user->id,
                'is_self' => $actor->is($user),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                ...$this->users->currentData($user),
            ],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->users->update($actor, $user, $request->payload());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account updated.')]);

        return to_route('admin.users.index');
    }

    public function setStatus(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        /** @var User $actor */
        $actor = $request->user();
        $this->users->setActive($actor, $user, (bool) $validated['is_active']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['is_active'] ? __(':name reactivated.', ['name' => $user->name]) : __(':name deactivated.', ['name' => $user->name]),
        ]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'roles' => RoleOrder::query()->pluck('name'),
            'locations' => Branch::query()->orderBy('location_type')->orderBy('code')->get()->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'type' => $branch->location_type->value,
                'is_active' => $branch->is_active,
            ]),
        ];
    }
}
