<?php

namespace App\Enums;

/**
 * Order lifecycle (FR-046, D-044, D-045). Preparation moves an order one
 * step forward at a time; cancelling is a separate, permissioned action
 * (D-123). `refunded` is reached only through refunds (Phase 9).
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'New',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }

    /** The one status preparation staff can move this order to (D-045). */
    public function next(): ?self
    {
        return match ($this) {
            self::Pending => self::Confirmed,
            self::Confirmed => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Completed,
            default => null,
        };
    }

    /** Still on the preparation board. */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Preparing, self::Ready], true);
    }

    /** Can still be cancelled; a completed order needs a refund instead (D-123). */
    public function isCancellable(): bool
    {
        return $this->isActive();
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isActive()));
    }
}
