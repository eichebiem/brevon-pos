<?php

namespace App\Models;

use App\Enums\BeneficiaryType;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * SC/PWD beneficiary details for one order discount (D-068, D-148): the only
 * personal data V1 keeps. Encrypted at rest, never searchable or reused,
 * shown masked unless the viewer holds `discounts.view-beneficiary`.
 *
 * @property int $id
 * @property int $order_discount_id
 * @property BeneficiaryType $beneficiary_type
 * @property string $full_name
 * @property string $card_number
 * @property string $address
 */
class OrderDiscountBeneficiary extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['full_name', 'card_number', 'address'];

    protected function casts(): array
    {
        return [
            'beneficiary_type' => BeneficiaryType::class,
            'full_name' => 'encrypted',
            'card_number' => 'encrypted',
            'address' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Beneficiary details are never changed.'));
        static::deleting(fn () => throw new LogicException('Beneficiary details are never deleted.'));
    }

    /** "Juan Dela Cruz" → "J*** D*** C***". */
    public function maskedName(): string
    {
        return implode(' ', array_map(fn (string $part) => mb_substr($part, 0, 1).'***', preg_split('/\s+/', trim($this->full_name)) ?: []));
    }

    /** Only the last 4 characters of the card number. */
    public function maskedCard(): string
    {
        $card = preg_replace('/\s+/', '', $this->card_number) ?? '';

        return '••••'.mb_substr($card, -4);
    }

    /**
     * @return array{type: string, type_label: string, name: string, card_number: string, address: string|null, masked: bool}
     */
    public function forDisplay(bool $full): array
    {
        return [
            'type' => $this->beneficiary_type->value,
            'type_label' => $this->beneficiary_type->label(),
            'name' => $full ? $this->full_name : $this->maskedName(),
            'card_number' => $full ? $this->card_number : $this->maskedCard(),
            'address' => $full ? $this->address : null,
            'masked' => ! $full,
        ];
    }
}
