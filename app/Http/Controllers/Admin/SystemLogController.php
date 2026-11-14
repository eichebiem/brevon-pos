<?php

namespace App\Http\Controllers\Admin;

use App\Domain\System\LogReader;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System health and recent error logs for technical administrators (FR-102,
 * D-184). Read-only; secrets are masked by LogReader. Route middleware:
 * permission:system.logs (Owner by default).
 */
class SystemLogController extends Controller
{
    public function index(Request $request, LogReader $logs): Response
    {
        $filters = $request->validate([
            'level' => ['nullable', Rule::in(LogReader::LEVELS)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $file = $logs->file();

        return Inertia::render('admin/system/Logs', [
            'health' => [
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'https' => (bool) config('brevon.force_https'),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'database' => $this->databaseOk(),
                'log_file' => $file !== null ? basename($file) : null,
                'log_size' => $file !== null ? (int) filesize($file) : 0,
                'last_backup' => $this->lastBackup(),
            ],
            'entries' => $logs->entries($filters['level'] ?? null, $filters['search'] ?? null),
            'levels' => LogReader::LEVELS,
            'filters' => (object) $filters,
        ]);
    }

    /** @return array{name: string, at: string, size: int}|null */
    private function lastBackup(): ?array
    {
        $files = glob(config('brevon.backup.path').'/*.sql.gz') ?: [];
        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
        if ($files === []) {
            return null;
        }

        return [
            'name' => basename($files[0]),
            'at' => CarbonImmutable::createFromTimestamp((int) filemtime($files[0]))->toIso8601String(),
            'size' => (int) filesize($files[0]),
        ];
    }

    private function databaseOk(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
