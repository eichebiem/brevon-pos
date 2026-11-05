<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $precision
 */
class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    protected $fillable = ['code', 'name', 'precision'];

    protected function casts(): array
    {
        return ['precision' => 'integer'];
    }
}
