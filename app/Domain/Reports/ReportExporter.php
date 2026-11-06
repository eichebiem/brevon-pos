<?php

namespace App\Domain\Reports;

use App\Domain\Shared\BusinessClock;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a report table as CSV or XLSX (FR-096, D-163). Text cells can
 * never run as spreadsheet formulas: CSV prefixes them with an apostrophe,
 * XLSX writes them as plain text cells. Amounts and quantities are numbers
 * in XLSX so they can be summed; times are shown in Manila time.
 */
class ReportExporter
{
    public function download(ReportTable $table, string $format, string $fileStem): StreamedResponse
    {
        $header = array_map(fn (array $c) => $c['label'], $table->columns);
        $rows = $table->rows;
        if ($table->totals !== null) {
            $rows[] = $table->totals;
        }

        if ($format === 'xlsx') {
            return response()->streamDownload(function () use ($table, $header, $rows) {
                $writer = new Writer;
                $writer->openToFile('php://output');
                $writer->getCurrentSheet()->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $table->title) ?? 'Report', 0, 31));
                $writer->addRow(new Row(array_map(fn (string $h) => new StringCell($h), $header)));
                foreach ($rows as $row) {
                    $writer->addRow(new Row(array_map(fn (array $c) => $this->xlsxCell($row[$c['key']] ?? null, $c['type']), $table->columns)));
                }
                $writer->close();
            }, "{$fileStem}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($table, $header, $rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ and ñ correctly
            fputcsv($out, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (array $c) => self::csvCell($this->text($row[$c['key']] ?? null, $c['type'])), $table->columns), escape: '');
            }
            fclose($out);
        }, "{$fileStem}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stops a spreadsheet from running a CSV cell as a formula. */
    public static function csvCell(string $text): string
    {
        return $text !== '' && ! is_numeric($text) && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    private function xlsxCell(string|int|null $value, string $type): Cell
    {
        if ($value === null || $value === '') {
            return new StringCell('');
        }
        if (in_array($type, ['money', 'quantity', 'int', 'percent'], true) && is_numeric($value)) {
            return new NumericCell(0 + $value);
        }

        return new StringCell($this->text($value, $type));
    }

    private function text(string|int|null $value, string $type): string
    {
        if ($value === null) {
            return '';
        }
        if ($type === 'datetime' && is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->setTimezone(BusinessClock::timezone())->format('Y-m-d H:i');
        }

        return (string) $value;
    }
}
