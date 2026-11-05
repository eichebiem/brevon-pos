<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $option_group_id
 * @property string $name
 * @property string $price_delta
 * @property bool $is_active
 * @property int $sort_order
 */
class ProductOption extends Model
{
    protected $fillable = ['option_group_id', 'name', 'price_delta', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function delete(): never
    {
        throw new LogicException('Options cannot be deleted; deactivate them instead.');
    }

    /**
     * @return BelongsTo<OptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }
}
