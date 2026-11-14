<?php

namespace Tests\Feature\Auth;

use App\Domain\Access\PermissionCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** D-186: after sign-in, only go back to a page the person may open. */
class LoginRedirectTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    private function signIn(User $user, string $intended): TestResponse
    {
        return $this->withSession(['url.intended' => $intended])
            ->post(route('login.store'), ['login' => $user->username, 'password' => 'password']);
    }

    public function test_a_cashier_is_not_sent_to_an_admin_page_left_by_someone_else(): void
    {
        $cashier = $this->staff(PermissionCatalog::CASHIER);

        $this->signIn($cashier, url('/admin/users'))->assertRedirect(url('/home'));
        $this->assertAuthenticatedAs($cashier);
    }

    public function test_someone_allowed_goes_back_to_the_page_they_wanted(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);

        $this->signIn($owner, url('/admin/users?status=active'))->assertRedirect(url('/admin/users?status=active'));
    }

    public function test_pages_on_other_sites_or_that_do_not_exist_go_to_the_start_page(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);

        $this->signIn($owner, 'https://evil.example/admin/users')->assertRedirect(url('/home'));
        $this->post('/logout');
        $this->signIn($owner, url('/nowhere'))->assertRedirect(url('/home'));
    }
}
