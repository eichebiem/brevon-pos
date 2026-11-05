<?php

namespace App\Domain\Inventory;

use RuntimeException;

/**
 * Thrown when a change would take a balance below zero and negative stock is
 * not allowed (FR-062, BR-013). The whole ledger transaction is rolled back.
 */
class InsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly int $branchId,
        public readonly int $inventoryItemId,
        public readonly string $itemName,
        public readonly string $available,
        public readonly string $requested,
    ) {
        parent::__construct("Not enough stock of {$itemName}: {$available} available, {$requested} needed.");
    }
}
