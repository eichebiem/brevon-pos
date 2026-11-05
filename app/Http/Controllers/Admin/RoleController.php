<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\AccessGuard;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\RoleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/** Roles and permissions (FR-006, FR-010). Route middleware: permission:roles.manage. */
class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): Response
    {
        $roles = Role::query()->withCount('permissions')->orderBy('name')->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'permissions_count' => $role->permissions_count,
            'users_count' => User::role($role->name)->count(),
            'is_system' => in_array($role->name, AccessGuard::SYSTEM_ROLES, true),
        ]);

        return Inertia::render('admin/roles/Index', ['roles' => $roles]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/roles/Form', [...$this->formOptions($request), 'role' => null]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var list<string> $permissions */
        $permissions = $request->validated('permissions');
        $this->roles->create($actor, (string) $request->validated('name'), $permissions);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route('admin.roles.index');
    }

    public function edit(Request $request, Role $role): Response
    {
        return Inertia::render('admin/roles/Form', [
            ...$this->formOptions($request),
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions()->pluck('name'),
                'is_system' => in_array($role->name, AccessGuard::SYSTEM_ROLES, true),
                'is_owner' => $role->name === PermissionCatalog::OWNER,
            ],
        ]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var list<string> $permissions */
        $permissions = $request->validated('permissions');
        $this->roles->update($actor, $role, (string) $request->validated('name'), $permissions);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('admin.roles.index');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->roles->delete($actor, $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route('admin.roles.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Request $request): array
    {
        /** @var User $actor */
        $actor = $request->user();

        return [
            'groups' => PermissionCatalog::groups(),
            'grantable' => $actor->getAllPermissions()->pluck('name'),
        ];
    }
}
