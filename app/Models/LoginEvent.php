<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Login attempt history (FR-009, D-020). Append-only.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $identifier
 * @property bool $succeeded
 * @property string|null $reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 */
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    public const REASON_INVALID_CREDENTIALS = 'invalid_credentials';

    public const REASON_INACTIVE = 'inactive';

    public const REASON_THROTTLED = 'throttled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Login events are append-only.'));
        static::deleting(fn () => throw new LogicException('Login events are append-only.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
