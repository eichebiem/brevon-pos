<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property PaymentMethodType $type
 * @property bool $requires_reference
 * @property bool $is_active
 */
class PaymentMethod extends Model
{
    protected $fillable = ['code', 'name', 'type', 'requires_reference', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
