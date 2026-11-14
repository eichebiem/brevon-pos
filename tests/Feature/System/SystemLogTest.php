<?php

namespace Tests\Feature\System;

use App\Domain\Access\PermissionCatalog;
use App\Domain\System\LogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-102, D-184: health and masked logs for technical administrators. */
class SystemLogTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->dir = storage_path('framework/testing/logs-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        $this->app->instance(LogReader::class, new LogReader($this->dir));
        config(['brevon.backup.path' => $this->dir.'/backups']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_only_the_owner_sees_system_logs_by_default(): void
    {
        $this->actingAs($this->staff(PermissionCatalog::OWNER))->get('/admin/system/logs')->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/system/Logs')
                ->where('health.database', true)
                ->where('health.environment', 'testing')
                ->where('health.log_file', null)
                ->where('health.last_backup', null)
                ->where('entries', []));
        $this->actingAs($this->staff(PermissionCatalog::MANAGER))->get('/admin/system/logs')->assertForbidden();
        $this->actingAs($this->staff(PermissionCatalog::CASHIER))->get('/admin/system/logs')->assertForbidden();
        $this->assertContains('system.logs', PermissionCatalog::defaultRoles()[PermissionCatalog::OWNER]);
    }

    public function test_entries_are_newest_first_filterable_and_secrets_are_hidden(): void
    {
        config(['database.connections.mysql.password' => 'db-pass-XYZ']);
        $appKey = (string) config('app.key');
        File::put($this->dir.'/laravel.log', implode("\n", [
            'trailing fragment from a cut-off entry',
            '[2026-11-09 01:00:00] production.INFO: Started {"user":1}',
            '[2026-11-09 02:00:00] production.ERROR: SQLSTATE[HY000] Access denied for brevon using password: db-pass-XYZ {"exception":"[object] (PDOException',
            '#0 /app/vendor/x.php(10): connect(password=hunter22, token: abc123456)',
            'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig',
            'APP_KEY='.$appKey.' raw '.substr($appKey, 7),
            '[2026-11-09 03:00:00] production.WARNING: Low disk',
            '',
        ]));

        $response = $this->actingAs($this->staff(PermissionCatalog::OWNER))->get('/admin/system/logs')->assertOk();
        $page = $response->viewData('page')['props'];
        $this->assertSame(['warning', 'error', 'info'], array_column($page['entries'], 'level'));
        $this->assertSame('2026-11-09T02:00:00+00:00', $page['entries'][1]['at']);

        $all = json_encode($page['entries']);
        foreach (['db-pass-XYZ', 'hunter22', 'abc123456', 'eyJhbGciOiJIUzI1NiJ9', substr($appKey, 7)] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $all, $secret);
        }
        $this->assertStringContainsString('[hidden]', $page['entries'][1]['details']);
        $this->assertStringContainsString('Access denied for brevon', $page['entries'][1]['message']);

        $this->actingAs($this->staff(PermissionCatalog::OWNER))->get('/admin/system/logs?level=error')
            ->assertInertia(fn ($p) => $p->has('entries', 1)->where('entries.0.level', 'error'));
        $this->actingAs($this->staff(PermissionCatalog::OWNER))->get('/admin/system/logs?search=disk')
            ->assertInertia(fn ($p) => $p->has('entries', 1)->where('entries.0.message', 'Low disk'));
        $this->actingAs($this->staff(PermissionCatalog::OWNER))->get('/admin/system/logs?level=nonsense')->assertSessionHasErrors('level');
    }
}
