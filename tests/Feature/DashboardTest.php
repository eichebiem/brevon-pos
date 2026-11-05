<?php

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_users_with_dashboard_permission_can_visit_the_dashboard()
    {
        $this->seedAccess();

        $this->actingAs($this->staff(PermissionCatalog::MANAGER))->get(route('dashboard'))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertForbidden();
    }
}
