<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A tax rule (FR-086; D-149). None is seeded or active: turning one on is an
 * accountant's decision (BR-018). Switched off, never deleted.
 *
 * @property int $id
 * @property string $name
 * @property string|null $code
 * @property string $rate
 * @property string $calculation_method
 * @property bool $inclusive
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property bool $is_active
 */
class TaxRule extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'inclusive' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Tax rules are switched off, never deleted.'));
    }
}
