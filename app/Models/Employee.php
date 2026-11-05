<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Staff profile (SRS §8.2). Not a customer record.
 *
 * @property int $id
 * @property string $employee_code
 * @property string $first_name
 * @property string $last_name
 * @property string|null $middle_name
 * @property string|null $phone
 * @property string|null $email
 * @property CarbonImmutable|null $hire_date
 * @property string $employment_status
 * @property int $default_branch_id
 * @property string|null $notes
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_code', 'first_name', 'last_name', 'middle_name', 'phone', 'email',
        'hire_date', 'employment_status', 'default_branch_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['hire_date' => 'date'];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
