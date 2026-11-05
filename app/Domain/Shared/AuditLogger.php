<?php

namespace App\Domain\Shared;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes append-only audit records (FR-098, FR-099, NFR-013).
 * The auditable type is the enforced morph alias (D-030).
 */
class AuditLogger
{
    /** Keys never written to audit records. */
    private const REDACTED = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        string $event,
        Model $auditable,
        ?array $old = null,
        ?array $new = null,
        ?int $branchId = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= $this->request->user();
        $key = $auditable->getKey();

        return AuditLog::query()->create([
            'branch_id' => $branchId,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'event' => $event,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => is_int($key) ? $key : (int) $key,
            'old_values' => $this->clean($old),
            'new_values' => $this->clean($new),
            'ip_address' => app()->runningInConsole() ? null : $this->request->ip(),
            'user_agent' => app()->runningInConsole() ? 'console' : $this->request->userAgent(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function clean(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
