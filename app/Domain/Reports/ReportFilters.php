<?php

namespace App\Domain\Reports;

use App\Domain\Shared\BusinessClock;
use Carbon\CarbonImmutable;

/**
 * What a report covers (FR-095, D-157): business dates (Asia/Manila, both
 * ends included), the locations in scope (already limited to what the user
 * may see), and optional cashier, category and payment-method filters.
 */
final readonly class ReportFilters
{
    /**
     * @param  list<int>  $branchIds
     * @param  'day'|'week'|'month'  $groupBy
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $branchIds,
        public ?int $cashierId = null,
        public ?int $categoryId = null,
        public ?int $paymentMethodId = null,
        public string $groupBy = 'day',
        public string $location = 'all',
    ) {}

    /**
     * The same days as stored timestamps (UTC): from the start of the first
     * Manila day up to, not including, the start of the day after the last.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcRange(): array
    {
        $zone = BusinessClock::timezone();

        return [
            CarbonImmutable::parse($this->from->toDateString(), $zone)->startOfDay()->utc(),
            CarbonImmutable::parse($this->to->toDateString(), $zone)->addDay()->startOfDay()->utc(),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toQuery(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'location' => $this->location,
            'cashier' => $this->cashierId,
            'category' => $this->categoryId,
            'payment_method' => $this->paymentMethodId,
            'group' => $this->groupBy,
        ];
    }
}
