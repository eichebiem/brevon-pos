<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $branch_id
 * @property string $key
 * @property string|null $value
 * @property string $value_type
 * @property bool $is_sensitive
 */
class Setting extends Model
{
    protected $fillable = ['branch_id', 'key', 'value', 'value_type', 'is_sensitive'];

    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean'];
    }
}
