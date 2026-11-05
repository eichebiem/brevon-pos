<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LoginEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only audit and login history (FR-009, FR-099, FR-100).
 * Route middleware: permission:audit.view. There are no write routes.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['event' => ['nullable', 'string', 'max:100'], 'entity' => ['nullable', 'string', 'max:50']]);

        $logs = AuditLog::query()
            ->with(['user:id,name', 'branch:id,code'])
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event', 'like', "{$event}%"))
            ->when($filters['entity'] ?? null, fn ($q, $entity) => $q->where('auditable_type', $entity))
            ->tap(fn ($q) => $this->applyDateRange($q, $filters))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at->toIso8601String(),
                'event' => $log->event,
                'entity' => $log->auditable_type,
                'entity_id' => $log->auditable_id,
                'user' => $log->user?->name,
                'location' => $log->branch?->code,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
            ]);

        return Inertia::render('admin/audit/Index', [
            'logs' => $logs,
            'filters' => (object) $filters,
            'entities' => AuditLog::query()->distinct()->orderBy('auditable_type')->pluck('auditable_type'),
        ]);
    }

    public function logins(Request $request): Response
    {
        $filters = $this->filters($request, [
            'search' => ['nullable', 'string', 'max:150'],
            'result' => ['nullable', 'in:success,failed'],
        ]);

        $events = LoginEvent::query()
            ->with('user:id,name')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('identifier', 'like', "%{$search}%"))
            ->when(($filters['result'] ?? null) === 'success', fn ($q) => $q->where('succeeded', true))
            ->when(($filters['result'] ?? null) === 'failed', fn ($q) => $q->where('succeeded', false))
            ->tap(fn ($q) => $this->applyDateRange($q, $filters))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (LoginEvent $event) => [
                'id' => $event->id,
                'created_at' => $event->created_at->toIso8601String(),
                'identifier' => $event->identifier,
                'user' => $event->user?->name,
                'succeeded' => $event->succeeded,
                'reason' => $event->reason,
                'ip_address' => $event->ip_address,
            ]);

        return Inertia::render('admin/audit/Logins', ['events' => $events, 'filters' => (object) $filters]);
    }

    /**
     * @param  array<string, array<int, string>>  $extra
     * @return array<string, mixed>
     */
    private function filters(Request $request, array $extra): array
    {
        return $request->validate([
            ...$extra,
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    /**
     * Date filters are Asia/Manila calendar days converted to UTC (BR-020, D-031).
     *
     * @param  Builder<AuditLog>|Builder<LoginEvent>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateRange($query, array $filters): void
    {
        $tz = (string) config('brevon.business_timezone');

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse((string) $filters['from'], $tz)->startOfDay()->utc());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse((string) $filters['to'], $tz)->addDay()->startOfDay()->utc());
        }
    }
}
