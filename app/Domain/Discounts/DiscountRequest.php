<?php

namespace App\Domain\Discounts;

/**
 * A discount asked for at the counter (D-146). `lineIndexes` (SC/PWD only)
 * are the cart lines the beneficiary is having; null means the whole order.
 * The approver's password is checked once and never stored or hashed into
 * the idempotency fingerprint.
 */
final readonly class DiscountRequest
{
    /**
     * @param  list<int>|null  $lineIndexes
     */
    public function __construct(
        public int $discountId,
        public ?array $lineIndexes = null,
        public ?BeneficiaryInput $beneficiary = null,
        public ?string $approverUsername = null,
        public ?string $approverPassword = null,
        public ?string $reason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return [
            'id' => $this->discountId,
            'lines' => $this->lineIndexes,
            'beneficiary' => $this->beneficiary === null ? null : hash('sha256', (string) json_encode([
                $this->beneficiary->type, $this->beneficiary->fullName, $this->beneficiary->cardNumber, $this->beneficiary->address,
            ])),
            'approver' => $this->approverUsername === null ? null : mb_strtolower($this->approverUsername),
            'reason' => $this->reason,
        ];
    }
}
