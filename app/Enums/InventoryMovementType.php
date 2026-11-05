<?php

namespace App\Enums;

/**
 * Stock movement types (SRS §8.7 inventory_movements). Each type has a fixed
 * direction, checked by the InventoryLedger.
 */
enum InventoryMovementType: string
{
    case Opening = 'opening';
    case PurchaseReceipt = 'purchase_receipt';
    case ProductionConsumption = 'production_consumption';
    case ProductionOutput = 'production_output';
    case Sale = 'sale';
    case Waste = 'waste';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case ReturnIn = 'return_in';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    public function isInbound(): bool
    {
        return in_array($this, [
            self::Opening, self::PurchaseReceipt, self::ProductionOutput,
            self::AdjustmentIn, self::ReturnIn, self::TransferIn,
        ], true);
    }

    public function isTransfer(): bool
    {
        return $this === self::TransferIn || $this === self::TransferOut;
    }

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening stock',
            self::PurchaseReceipt => 'Received',
            self::ProductionConsumption => 'Used in production',
            self::ProductionOutput => 'Produced',
            self::Sale => 'Sold',
            self::Waste => 'Waste',
            self::AdjustmentIn => 'Adjustment (in)',
            self::AdjustmentOut => 'Adjustment (out)',
            self::ReturnIn => 'Returned',
            self::TransferIn => 'Transfer in',
            self::TransferOut => 'Transfer out',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
