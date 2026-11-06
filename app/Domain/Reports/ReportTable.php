<?php

namespace App\Domain\Reports;

/**
 * One table of a report: column definitions, rows and an optional totals
 * row. The same object is shown on screen and exported, so an export
 * always matches what was on screen (FR-096).
 *
 * Column types: text, money (decimal string), quantity (decimal string),
 * int, percent (decimal string), date (YYYY-MM-DD), datetime (ISO 8601).
 */
final readonly class ReportTable
{
    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, string|int|null>>  $rows
     * @param  array<string, string|int|null>|null  $totals
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $columns,
        public array $rows,
        public ?array $totals = null,
        public ?string $empty = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'empty' => $this->empty ?? 'Nothing in these dates.',
        ];
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    public static function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
