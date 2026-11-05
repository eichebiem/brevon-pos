<?php

namespace App\Models;

use App\Enums\LocationType;
use Carbon\CarbonImmutable;
use Database\Factories\BranchFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An operating location: the single permanent branch or a temporary pop-up
 * stand (SRS FR-011, FR-103; D-057). The table keeps the SRS name `branches`.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property LocationType $location_type
 * @property string|null $address_line
 * @property string|null $barangay
 * @property string|null $city_municipality
 * @property string|null $province
 * @property string|null $postal_code
 * @property string|null $phone
 * @property string|null $email
 * @property string $timezone
 * @property string $currency
 * @property bool $is_active
 * @property CarbonImmutable|null $operating_starts_on
 * @property CarbonImmutable|null $operating_ends_on
 * @property CarbonImmutable|null $deactivated_at
 * @property int|null $deactivated_by
 * @property string|null $deactivation_reason
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'location_type', 'address_line', 'barangay', 'city_municipality',
        'province', 'postal_code', 'phone', 'email', 'timezone', 'currency', 'is_active',
        'operating_starts_on', 'operating_ends_on',
    ];

    protected function casts(): array
    {
        return [
            'location_type' => LocationType::class,
            'is_active' => 'boolean',
            'operating_starts_on' => 'date',
            'operating_ends_on' => 'date',
            'deactivated_at' => 'datetime',
        ];
    }

    /** Locations are deactivated, never deleted (D-062, FR-108). */
    public function delete(): never
    {
        throw new LogicException('Operating locations cannot be deleted; deactivate them instead.');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branch')->withPivot('is_default')->withTimestamps();
    }

    /**
     * @return HasMany<Terminal, $this>
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    public function isPermanent(): bool
    {
        return $this->location_type === LocationType::Permanent;
    }

    /**
     * D-059: may this location take NEW sales / shift openings on the given
     * Asia/Manila calendar date? Pop-up dates are optional and only enforced
     * when set.
     */
    public function acceptsNewOperationsOn(DateTimeInterface $manilaDate): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isPermanent()) {
            return true;
        }

        $day = $manilaDate->format('Y-m-d');

        if ($this->operating_starts_on !== null && $day < $this->operating_starts_on->format('Y-m-d')) {
            return false;
        }

        return $this->operating_ends_on === null || $day <= $this->operating_ends_on->format('Y-m-d');
    }

    /**
     * @param  Builder<Branch>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Short display label, e.g. "BRV01 · Main".
     */
    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
