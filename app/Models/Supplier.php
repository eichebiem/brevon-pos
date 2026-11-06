<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A supplier (FR-058; D-135). Never deleted in the app: deactivated instead.
 *
 * @property int $id
 * @property string $supplier_code
 * @property string $name
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $payment_terms
 * @property bool $is_active
 */
class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'address', 'payment_terms', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
