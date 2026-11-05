<?php

namespace App\Domain\Access;

use App\Domain\Shared\AuditLogger;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * Creates and updates staff accounts (FR-005, FR-006, FR-010) with audit
 * history (FR-098). Accounts are deactivated, never deleted.
 *
 * Expected $data keys: name, username, email, password (nullable on update),
 * is_active, roles (list<string>), branch_ids (list<int>), default_branch_id,
 * employee (array|null: employee_code, first_name, last_name, middle_name,
 * phone, hire_date).
 */
class UserService
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): User
    {
        /** @var list<string> $roles */
        $roles = $data['roles'];
        $this->guard->assertCanAssignRoles($actor, $roles);

        return DB::transaction(function () use ($actor, $data, $roles) {
            $user = User::query()->create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => (bool) $data['is_active'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->syncEmployee($user, $data);
            $user->syncRoles($roles);
            $this->syncBranches($user, $data);

            $this->audit->record('user.created', $user, null, $this->snapshot($user), actor: $actor);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, User $user, array $data): User
    {
        /** @var list<string> $roles */
        $roles = $data['roles'];
        $active = (bool) $data['is_active'];

        $this->guard->assertCanManageUser($actor, $user);
        $this->guard->assertNotChangingOwnAccess($actor, $user, $roles, $active);
        if ($roles !== $user->getRoleNames()->all()) {
            $this->guard->assertCanAssignRoles($actor, $roles);
        }
        $this->guard->assertKeepsAnActiveOwner($user, $roles, $active);

        return DB::transaction(function () use ($actor, $user, $data, $roles, $active) {
            $before = $this->snapshot($user);
            $passwordChanged = filled($data['password'] ?? null);

            $user->fill([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'is_active' => $active,
            ]);
            if ($passwordChanged) {
                $user->password = (string) $data['password'];
            }
            $user->save();

            $this->syncEmployee($user, $data);
            $user->syncRoles($roles);
            $this->syncBranches($user, $data);

            $after = $this->snapshot($user->refresh());
            [$old, $new] = $this->diff($before, $after);

            if ($passwordChanged) {
                // Value is redacted by AuditLogger; the key records that it changed.
                $new['password'] = 'changed';
            }

            if ($old !== [] || $new !== []) {
                $event = match (true) {
                    $before['is_active'] && ! $after['is_active'] => 'user.deactivated',
                    ! $before['is_active'] && $after['is_active'] => 'user.reactivated',
                    default => 'user.updated',
                };
                $this->audit->record($event, $user, $old, $new, actor: $actor);
            }

            if (! $active || $passwordChanged) {
                $this->endSessions($user);
            }

            return $user;
        });
    }

    public function setActive(User $actor, User $user, bool $active): User
    {
        return $this->update($actor, $user, [
            ...$this->currentData($user),
            'is_active' => $active,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function currentData(User $user): array
    {
        $user->loadMissing('employee', 'branches');

        return [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'password' => null,
            'is_active' => $user->is_active,
            'roles' => $user->getRoleNames()->values()->all(),
            'branch_ids' => $user->branches->pluck('id')->all(),
            'default_branch_id' => $this->defaultBranchId($user),
            'employee' => $user->employee?->only(['employee_code', 'first_name', 'last_name', 'middle_name', 'phone', 'hire_date']),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncBranches(User $user, array $data): void
    {
        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_map('intval', $data['branch_ids'] ?? [])));
        $default = isset($data['default_branch_id']) ? (int) $data['default_branch_id'] : ($ids[0] ?? null);

        $user->branches()->sync(collect($ids)->mapWithKeys(fn (int $id) => [$id => ['is_default' => $id === $default]])->all());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncEmployee(User $user, array $data): void
    {
        /** @var array<string, mixed>|null $profile */
        $profile = $data['employee'] ?? null;

        if ($profile === null) {
            return;
        }

        $attributes = [
            'employee_code' => $profile['employee_code'],
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name'],
            'middle_name' => $profile['middle_name'] ?? null,
            'phone' => $profile['phone'] ?? null,
            'hire_date' => $profile['hire_date'] ?? null,
            'email' => $user->email,
            'default_branch_id' => $data['default_branch_id'] ?? ($data['branch_ids'][0] ?? null),
        ];

        if ($user->employee_id !== null && ($employee = Employee::query()->find($user->employee_id)) !== null) {
            $employee->update($attributes);

            return;
        }

        $employee = Employee::query()->create($attributes);
        $user->forceFill(['employee_id' => $employee->id])->save();
    }

    /**
     * Sign the user out everywhere (database session driver).
     */
    private function endSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $user->forceFill(['remember_token' => null])->saveQuietly();

        if (Session::isStarted() && auth()->id() === $user->id) {
            Session::invalidate();
        }
    }

    private function defaultBranchId(User $user): ?int
    {
        $id = DB::table('user_branch')->where('user_id', $user->id)->where('is_default', true)->value('branch_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        $user->loadMissing('employee', 'branches');

        return [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $user->getRoleNames()->sort()->values()->all(),
            'locations' => $user->branches->sortBy('code')->pluck('code')->values()->all(),
            'default_location' => $user->branches->firstWhere('id', $this->defaultBranchId($user))?->code,
            'employee_code' => $user->employee?->employee_code,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $old[$key] = $before[$key] ?? null;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }
}
