<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalog;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-001, FR-002, FR-009 · T-AUTH-1..6 */
class LoginTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    public function test_staff_can_sign_in_with_username(): void
    {
        $user = $this->staff(PermissionCatalog::CASHIER, attributes: ['username' => 'maria.cashier']);

        $this->post(route('login.store'), ['login' => 'maria.cashier', 'password' => 'password'])
            ->assertRedirect(route('landing', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'succeeded' => true, 'identifier' => 'maria.cashier']);
    }

    public function test_staff_can_sign_in_with_email(): void
    {
        $user = $this->staff(PermissionCatalog::CASHIER);

        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_and_unknown_account_give_the_same_generic_error(): void
    {
        $user = $this->staff(PermissionCatalog::CASHIER, attributes: ['username' => 'known']);

        $wrong = $this->post(route('login.store'), ['login' => 'known', 'password' => 'not-it'])->assertSessionHasErrors('login');
        $wrongMessage = session('errors')->first('login');

        $this->post(route('login.store'), ['login' => 'nobody-here', 'password' => 'not-it'])->assertSessionHasErrors('login');
        $unknownMessage = session('errors')->first('login');

        $this->assertGuest();
        $this->assertSame($wrongMessage, $unknownMessage);
        $this->assertStringNotContainsStringIgnoringCase('inactive', $wrongMessage);
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'succeeded' => false, 'reason' => LoginEvent::REASON_INVALID_CREDENTIALS]);
        $this->assertDatabaseHas('login_events', ['user_id' => null, 'identifier' => 'nobody-here', 'succeeded' => false]);
        $this->assertDatabaseMissing('login_events', ['identifier' => 'not-it']);
    }

    public function test_inactive_account_is_refused_with_a_clear_message_only_after_the_correct_password(): void
    {
        $user = $this->staff(PermissionCatalog::CASHIER, attributes: ['username' => 'gone', 'is_active' => false]);

        $this->post(route('login.store'), ['login' => 'gone', 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->assertStringNotContainsStringIgnoringCase('inactive', session('errors')->first('login'));

        $this->post(route('login.store'), ['login' => 'gone', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertStringContainsStringIgnoringCase('inactive', session('errors')->first('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'reason' => LoginEvent::REASON_INACTIVE]);
    }

    public function test_repeated_failures_are_throttled_and_recorded(): void
    {
        $this->staff(PermissionCatalog::CASHIER, attributes: ['username' => 'target']);

        foreach (range(1, 5) as $ignored) {
            $this->post(route('login.store'), ['login' => 'target', 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['login' => 'target', 'password' => 'password'])->assertSessionHasErrors('login');

        $this->assertGuest();
        $this->assertStringContainsString('Too many', session('errors')->first('login'));
        $this->assertSame(5, LoginEvent::query()->where('reason', LoginEvent::REASON_INVALID_CREDENTIALS)->count());
        $this->assertDatabaseHas('login_events', ['identifier' => 'target', 'reason' => LoginEvent::REASON_THROTTLED]);
    }

    public function test_deactivated_user_loses_their_session_on_the_next_request(): void
    {
        $user = $this->staff(PermissionCatalog::CASHIER);
        $this->actingAs($user)->get(route('pos.index'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('pos.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_soft_deleted_user_cannot_sign_in(): void
    {
        $user = $this->staff(PermissionCatalog::CASHIER, attributes: ['username' => 'removed']);
        $user->delete();

        $this->post(route('login.store'), ['login' => 'removed', 'password' => 'password']);

        $this->assertGuest();
        $this->assertSoftDeleted(User::class, ['id' => $user->id]);
    }
}
