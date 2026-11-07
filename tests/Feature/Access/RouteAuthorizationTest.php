<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-006, FR-007, NFR-003 · T-AUTH-7, T-RBAC-1..3: server-side checks on every protected route. */
class RouteAuthorizationTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    private const ROUTES = [
        '/admin/dashboard', '/pos',
        '/admin/users', '/admin/users/create', '/admin/roles', '/admin/roles/create', '/admin/audit-logs', '/admin/audit-logs/logins',
        '/admin/locations', '/admin/locations/create', '/admin/settings',
        '/admin/products', '/admin/products/create', '/admin/categories', '/admin/categories/create',
        '/staff/shift', '/admin/inventory/stock', '/admin/inventory/movements', '/admin/inventory/opening-stock',
        '/admin/payment-methods', '/pos/orders', '/admin/shifts',
        '/admin/inventory/items', '/admin/inventory/adjustments', '/admin/inventory/dispatches',
        '/admin/suppliers', '/admin/purchase-orders', '/admin/receipts', '/admin/inventory/valuation',
        '/admin/recipes', '/admin/production', '/admin/discounts',
        '/admin/reports/sales', '/admin/reports/refunds', '/admin/reports/waste',
    ];

    /** Expected access per seeded role, in ROUTES order. */
    private const MATRIX = [
        PermissionCatalog::OWNER => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
        PermissionCatalog::MANAGER => [1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 1, 0, 1, 1, 1, 1, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 1, 0, 1, 1, 1],
        PermissionCatalog::CASHIER => [0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 1, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function roles(): array
    {
        return array_combine(array_keys(self::MATRIX), array_map(fn ($role) => [$role], array_keys(self::MATRIX)));
    }

    #[DataProvider('roles')]
    public function test_role_matrix(string $role): void
    {
        $this->seedAccess();
        $user = $this->staff($role);

        foreach (self::ROUTES as $i => $uri) {
            $status = $this->actingAs($user)->get($uri)->getStatusCode();
            $this->assertSame(self::MATRIX[$role][$i] ? 200 : 403, $status, "{$role} → {$uri}");
        }
    }

    public function test_user_without_any_role_is_refused_everywhere_but_their_profile(): void
    {
        $this->seedAccess();
        $user = User::factory()->create();

        foreach (self::ROUTES as $uri) {
            $this->actingAs($user)->get($uri)->assertForbidden();
        }

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function landings(): array
    {
        return [
            'owner' => [PermissionCatalog::OWNER, '/admin/dashboard'],
            'manager' => [PermissionCatalog::MANAGER, '/admin/dashboard'],
            'cashier' => [PermissionCatalog::CASHIER, '/pos'],
        ];
    }

    #[DataProvider('landings')]
    public function test_home_sends_each_role_to_its_area(string $role, string $target): void
    {
        $this->seedAccess();

        $this->actingAs($this->staff($role))->get('/home')->assertRedirect($target);
    }

    public function test_write_routes_are_protected_too(): void
    {
        $this->seedAccess();
        $cashier = $this->staff(PermissionCatalog::CASHIER);
        $other = $this->staff(PermissionCatalog::CASHIER);

        $this->actingAs($cashier)->post('/admin/users', [])->assertForbidden();
        $this->actingAs($cashier)->put("/admin/users/{$other->id}", [])->assertForbidden();
        $this->actingAs($cashier)->patch("/admin/users/{$other->id}/status", ['is_active' => false])->assertForbidden();
        $this->actingAs($cashier)->post('/admin/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();

        $this->assertTrue($other->refresh()->is_active);
    }
}
