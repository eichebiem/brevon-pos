<?php

namespace Tests\Feature\System;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** Phase 1 foundation checks (T-SYS-1 and configuration decisions). */
class FoundationTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    public function test_health_check_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_root_sends_staff_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_timestamps_are_utc_and_business_timezone_is_manila(): void
    {
        // D-031, BR-020, NFR-015
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Manila', config('brevon.business_timezone'));
        $this->assertSame('PHP', config('brevon.currency'));
    }

    public function test_tests_run_on_the_dedicated_mysql_test_database(): void
    {
        // D-013: concurrency and DECIMAL behaviour are tested on the production engine.
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('brevon_pos_test', config('database.connections.mysql.database'));
    }

    public function test_self_registration_is_disabled(): void
    {
        // D-017
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_passkeys_and_email_verification_are_disabled(): void
    {
        // D-019, D-070
        $this->assertFalse(Route::has('well-known.passkeys'));
        $this->assertFalse(Route::has('verification.notice'));
        $this->assertFalse(collect(Route::getRoutes()->getRoutes())->contains(
            fn ($route) => str_contains($route->uri(), 'passkey'),
        ));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function shells(): array
    {
        return [
            'admin dashboard' => ['/admin/dashboard', 'admin/Dashboard'],
            'pos' => ['/pos', 'pos/Index'],
            'production' => ['/admin/production', 'admin/production/batches/Index'],
        ];
    }

    #[DataProvider('shells')]
    public function test_area_shells_require_authentication_and_permission(string $uri, string $component): void
    {
        $this->get($uri)->assertRedirect('/login');

        $this->seedAccess();

        $this->actingAs(User::factory()->create())->get($uri)->assertForbidden();

        $this->actingAs($this->staff(PermissionCatalog::OWNER))
            ->get($uri)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }
}
