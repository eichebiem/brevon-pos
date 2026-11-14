<?php

namespace Tests\Feature\System;

use App\Domain\Access\PermissionCatalog;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\DevTools\DevTools;
use RuntimeException;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** Phase 11b hardening (NFR-001, NFR-005, NFR-020; D-183). */
class HardeningTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    public function test_every_response_carries_the_security_headers(): void
    {
        $this->get('/login')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'")
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_https_is_forced_when_switched_on_except_for_the_health_check(): void
    {
        config(['brevon.force_https' => true]);

        $this->get('http://localhost/login?x=1')->assertStatus(301)->assertRedirect('https://localhost/login?x=1');
        $this->get('https://localhost/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->get('http://localhost/up')->assertOk();
    }

    public function test_a_trusted_proxy_can_tell_the_app_the_request_was_https(): void
    {
        config(['brevon.force_https' => true, 'brevon.trusted_proxies' => '*']);
        (new AppServiceProvider($this->app))->boot();

        try {
            $this->get('http://localhost/login', ['X-Forwarded-Proto' => 'https'])->assertOk();
        } finally {
            TrustProxies::flushState();
        }
        $this->get('http://localhost/login', ['X-Forwarded-Proto' => 'https'])->assertStatus(301);
    }

    public function test_https_and_secure_cookies_default_on_in_production_only(): void
    {
        $this->assertFalse(config('brevon.force_https'), 'off in testing');
        $config = file_get_contents(config_path('brevon.php'));
        $this->assertStringContainsString("env('BREVON_FORCE_HTTPS', env('APP_ENV') === 'production')", (string) $config);
        $this->assertStringContainsString("env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production')", (string) file_get_contents(config_path('session.php')));
    }

    public function test_inertia_devtools_are_off_outside_local(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse(DevTools::enabled());
    }

    public function test_not_found_and_forbidden_show_the_friendly_page(): void
    {
        $cashier = $this->staff(PermissionCatalog::CASHIER);

        $this->actingAs($cashier)->get('/no-such-page')->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('errors/Show')->where('status', 404));
        $this->actingAs($cashier)->get('/admin/users')->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('errors/Show')->where('status', 403));
    }

    public function test_a_server_error_shows_no_stack_trace_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__boom', fn () => throw new RuntimeException('secret detail SQLSTATE'));

        $response = $this->get('/__boom')->assertStatus(500)
            ->assertInertia(fn ($page) => $page->component('errors/Show')->where('status', 500));
        $this->assertStringNotContainsString('secret detail', (string) $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', (string) $response->getContent());
    }

    public function test_a_failed_form_never_keeps_approval_passwords_or_sc_pwd_details(): void
    {
        Route::middleware('web')->post('/__validate', fn (Request $request) => $request->validate(['missing' => 'required']));

        $this->from('/login')->post('/__validate', [
            'notes' => 'kept',
            'approver_password' => 'manager-secret',
            'discount' => [
                'approver' => ['username' => 'mgr', 'password' => 'manager-secret'],
                'beneficiary' => ['full_name' => 'Juan Dela Cruz', 'card_number' => 'SC-123', 'address' => 'Manila'],
            ],
        ])->assertRedirect('/login')->assertSessionHasErrors('missing');

        $this->assertSame('kept', session()->getOldInput('notes'));
        $this->assertSame('mgr', session()->getOldInput('discount.approver.username'));
        $this->assertNull(session()->getOldInput('approver_password'));
        $this->assertNull(session()->getOldInput('discount.approver.password'));
        $this->assertNull(session()->getOldInput('discount.beneficiary'));
        $this->assertStringNotContainsString('manager-secret', serialize(session()->all()));
        $this->assertStringNotContainsString('Juan Dela Cruz', serialize(session()->all()));
    }

    public function test_an_expired_form_goes_back_with_a_message(): void
    {
        Route::middleware('web')->post('/__expired', fn () => throw new TokenMismatchException);

        $this->from('/login')->post('/__expired')->assertRedirect('/login')
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'The page expired. Please try again.');
    }
}
