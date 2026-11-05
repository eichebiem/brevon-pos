<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Shared\AuditLogger;
use App\Models\LoginEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-009, FR-098..FR-100, NFR-004 · T-AUD-1..4 */
class AuditLogTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
    }

    public function test_audit_entries_cannot_be_changed_or_deleted(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $log = $this->app->make(AuditLogger::class)->record('test.event', $owner, null, ['a' => 1], actor: $owner);

        try {
            $log->update(['event' => 'tampered']);
            $this->fail('Audit entries must be immutable.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Audit entries must not be deletable.');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'event' => 'test.event']);
    }

    public function test_login_events_are_immutable(): void
    {
        $event = LoginEvent::query()->create(['identifier' => 'x', 'succeeded' => false, 'reason' => LoginEvent::REASON_INVALID_CREDENTIALS]);

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_sensitive_keys_are_redacted(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $log = $this->app->make(AuditLogger::class)->record('test.event', $owner, null, ['password' => 'secret', 'remember_token' => 'abc', 'name' => 'ok'], actor: $owner);

        $this->assertSame('ok', $log->new_values['name']);
        $this->assertNotSame('secret', $log->new_values['password'] ?? null);
        $this->assertNotSame('abc', $log->new_values['remember_token'] ?? null);
    }

    public function test_viewer_filters_by_manila_calendar_day(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $logger = $this->app->make(AuditLogger::class);

        // 2026-11-04 23:30 Manila = 15:30 UTC; 2026-11-05 00:30 Manila = 16:30 UTC on the 4th.
        CarbonImmutable::setTestNow('2026-11-04 15:30:00');
        $logger->record('day.one', $owner, actor: $owner);
        CarbonImmutable::setTestNow('2026-11-04 16:30:00');
        $logger->record('day.two', $owner, actor: $owner);
        CarbonImmutable::setTestNow();

        $this->actingAs($owner)->get('/admin/audit-logs?event=day.&from=2026-11-05&to=2026-11-05')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/audit/Index')
                ->has('logs.data', 1)
                ->where('logs.data.0.event', 'day.two'));
    }

    public function test_login_history_lists_failed_attempts(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);
        $this->post(route('login.store'), ['login' => 'someone', 'password' => 'nope']);

        $this->actingAs($owner)->get('/admin/audit-logs/logins?result=failed')
            ->assertInertia(fn ($page) => $page->component('admin/audit/Logins')
                ->has('events.data', 1)
                ->where('events.data.0.identifier', 'someone')
                ->where('events.data.0.reason', LoginEvent::REASON_INVALID_CREDENTIALS));
    }

    public function test_there_are_no_audit_write_routes(): void
    {
        $owner = $this->staff(PermissionCatalog::OWNER);

        $this->actingAs($owner)->post('/admin/audit-logs')->assertStatus(405);
        $this->actingAs($owner)->delete('/admin/audit-logs')->assertStatus(405);
    }
}
