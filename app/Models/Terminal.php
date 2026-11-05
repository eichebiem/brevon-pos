<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A POS terminal belonging to exactly one operating location (D-060).
 *
 * @property int $id
 * @property int $branch_id
 * @property string $terminal_code
 * @property string $name
 * @property bool $is_active
 */
class Terminal extends Model
{
    protected $fillable = ['branch_id', 'terminal_code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function delete(): never
    {
        throw new LogicException('Terminals cannot be deleted; deactivate them instead.');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<CashierShift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(CashierShift::class);
    }

    public function hasOpenShift(): bool
    {
        return $this->shifts()->open()->exists();
    }
}
