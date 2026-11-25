<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Form D — the attendance register — as a printable spreadsheet.
 *
 * Every employee occupies two spreadsheet rows, not one: the form records an IN
 * and an OUT time per day, stacked. The identity columns either side of the date
 * band (S. No. through Place of Work, and Summary onwards) are therefore merged
 * down over both rows of the pair.
 *
 * The printed column numbers run 1, 2, 3, 5, 6, 7, 8, 9, 10, 11 — the form has no
 * column 4, and the number row reproduces that gap rather than closing it, the
 * same way Form E skips 21-24. Column 7 is the whole date band, so one number
 * sits merged across all of it.
 *
 * The date band is as wide as the month is long, not a fixed 31: a February sheet
 * ends at 28 rather than carrying three columns that can never be filled in.
 *
 * The establishment header (name / owner / LIN) and column 11 (signature of the
 * register keeper) are written as empty ruled cells — there is no company
 * settings record to read the header from, and the signature is filled in by
 * hand once the register is printed.
 */
class AttendanceRegisterExport implements FromArray, WithEvents, WithTitle, WithColumnWidths, WithStrictNullComparison
{
    // WithStrictNullComparison keeps a calculated 0 as a written 0 rather than
    // letting the writer blank the cell — on an attendance register the
    // difference between "no overtime" and "nothing recorded" is the point.

    /** Spreadsheet rows the fixed header occupies before any data. */
    protected const HEADER_ROWS = 5;

    /** The first spreadsheet row a register line lands on. */
    protected const FIRST_DATA_ROW = 6;

    /** Columns to the left of the date band, in physical order. */
    protected const LEADING_COLUMNS = 5;

    /** Columns to the right of the date band: summary, OT, remarks, signature. */
    protected const TRAILING_COLUMNS = 4;

    /** Payload from AttendanceController::buildAttendanceRegister(). */
    protected array $data;

    protected array $rows;

    protected int $daysInMonth;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->rows = $data['rows'] ?? [];
        $this->daysInMonth = (int) ($data['days_in_month'] ?? 31);
    }

    public function title(): string
    {
        return substr('Form D ' . ($this->data['month'] ?? ''), 0, 31);
    }

    /** Labels that span the band row and the sub-label row beneath it. */
    protected function outerLabels(): array
    {
        return [
            1 => 'S. No. In Employee Register',
            2 => 'Name',
            3 => 'Relay# or set work',
            4 => "Place of Work (Only in cases of Mines only) (Underground/Opencast/Surface)",
            5 => '',
            $this->summaryColumn() => 'Summary No. of Days',
            $this->summaryColumn() + 1 => 'No. of OT hours',
            $this->summaryColumn() + 2 => 'Remarks',
            $this->summaryColumn() + 3 => '**Signature of Register Keeper',
        ];
    }

    /**
     * The printed numbers against their physical column. Column 5 of the form
     * lands on the fourth physical column because the form has no column 4.
     */
    protected function printedNumbers(): array
    {
        return [
            1 => 1,
            2 => 2,
            3 => 3,
            4 => 5,
            5 => 6,
            self::LEADING_COLUMNS + 1 => 7,
            $this->summaryColumn() => 8,
            $this->summaryColumn() + 1 => 9,
            $this->summaryColumn() + 2 => 10,
            $this->summaryColumn() + 3 => 11,
        ];
    }

    protected function firstDayColumn(): int
    {
        return self::LEADING_COLUMNS + 1;
    }

    protected function lastDayColumn(): int
    {
        return self::LEADING_COLUMNS + $this->daysInMonth;
    }

    protected function summaryColumn(): int
    {
        return $this->lastDayColumn() + 1;
    }

    protected function totalColumns(): int
    {
        return self::LEADING_COLUMNS + $this->daysInMonth + self::TRAILING_COLUMNS;
    }

    protected function letter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    public function array(): array
    {
        $width = $this->totalColumns();

        // Rows 1-5 are written by the AfterSheet hook so they can be merged and
        // styled. The placeholders must be full-width rows of empty strings, not
        // empty arrays: fromArray() does not advance the row pointer for an empty
        // array, which would slide the data up under the header.
        $blank = array_fill(0, $width, '');

        $sheet = [
            array_replace($blank, [
                0 => 'Name of Establishment',
                4 => 'Name of Owner',
                (int) floor($width / 2) => 'LIN',
            ]),
            array_replace($blank, [4 => 'For the Month', 6 => $this->data['month'] ?? '']),
            $blank,
            $blank,
            $blank,
        ];

        foreach ($this->rows as $row) {
            $in = $blank;
            $out = $blank;

            $in[0] = $row['serial_no'];
            $in[1] = $row['name'];
            $in[2] = $row['relay'] ?? '';
            $in[3] = $row['place_of_work_label'] ?? '';
            $in[4] = 'IN';
            $out[4] = 'OUT';

            for ($day = 1; $day <= $this->daysInMonth; $day++) {
                $entry = $row['days'][$day] ?? null;
                $offset = self::LEADING_COLUMNS + $day - 1;

                $in[$offset] = $entry['in'] ?? '';
                $out[$offset] = $entry['out'] ?? '';
            }

            $summary = self::LEADING_COLUMNS + $this->daysInMonth;

            $in[$summary] = $row['total_days'];
            $in[$summary + 1] = $row['total_ot_hours'];
            $in[$summary + 2] = $row['remarks'] ?? '';
            $in[$summary + 3] = '';

            $sheet[] = $in;
            $sheet[] = $out;
        }

        return $sheet;
    }

    public function columnWidths(): array
    {
        $widths = [
            $this->letter(1) => 8,
            $this->letter(2) => 24,
            $this->letter(3) => 12,
            $this->letter(4) => 16,
            $this->letter(5) => 6,
            $this->letter($this->summaryColumn()) => 10,
            $this->letter($this->summaryColumn() + 1) => 10,
            $this->letter($this->summaryColumn() + 2) => 16,
            $this->letter($this->summaryColumn() + 3) => 14,
        ];

        // The day columns hold "HH:MM" and nothing else, so they stay narrow —
        // 31 of them on one landscape page only fits if they do.
        for ($column = $this->firstDayColumn(); $column <= $this->lastDayColumn(); $column++) {
            $widths[$this->letter($column)] = 6;
        }

        return $widths;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $last = $this->letter($this->totalColumns());
                $firstDay = $this->letter($this->firstDayColumn());
                $lastDay = $this->letter($this->lastDayColumn());
                $summary = $this->summaryColumn();

                // Two spreadsheet rows per employee.
                $lastRow = max(self::HEADER_ROWS, self::FIRST_DATA_ROW + (count($this->rows) * 2) - 1);

                // ── Establishment line, then the month ──
                $ruled = [
                    'B1:' . $this->letter(4) . '1',
                    $this->letter(5) . '1:' . $this->letter((int) floor($this->totalColumns() / 2) - 1) . '1',
                    $this->letter((int) floor($this->totalColumns() / 2) + 2) . '1:' . $last . '1',
                    $this->letter(7) . '2:' . $this->letter(12) . '2',
                ];

                foreach ($ruled as $range) {
                    $sheet->mergeCells($range);
                    $sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
                }

                $sheet->getStyle('A1:' . $last . '2')->getFont()->setBold(true);
                $sheet->getStyle($this->letter(7) . '2')->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // ── The date band over row 3, its day numbers on row 4 ──
                $sheet->setCellValue($firstDay . '3', 'Dates');
                $sheet->mergeCells($firstDay . '3:' . $lastDay . '3');

                for ($day = 1; $day <= $this->daysInMonth; $day++) {
                    $sheet->setCellValue([self::LEADING_COLUMNS + $day, 4], $day);
                }

                // ── Labels that span the band row and the one beneath it ──
                foreach ($this->outerLabels() as $column => $label) {
                    $letter = $this->letter($column);

                    $sheet->setCellValue($letter . '3', $label);
                    $sheet->mergeCells($letter . '3:' . $letter . '4');
                }

                // ── The printed column numbers, 1-3 then 5-11 ──
                foreach ($this->printedNumbers() as $column => $number) {
                    $sheet->setCellValue([$column, 5], $number);
                }

                // Column 7 numbers the whole date band, so it is written once.
                $sheet->mergeCells($firstDay . '5:' . $lastDay . '5');

                // The form prints its header white-on-black.
                $header = $sheet->getStyle('A3:' . $last . '5');
                $header->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF000000');
                $header->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);

                // Column 1's label is printed sideways on the form.
                $sheet->getStyle('A3:A4')->getAlignment()->setTextRotation(90);

                $sheet->getRowDimension(3)->setRowHeight(20);
                $sheet->getRowDimension(4)->setRowHeight(56);

                // ── Grid ──
                $sheet->getStyle('A3:' . $last . $lastRow)->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                if (count($this->rows) > 0) {
                    $first = self::FIRST_DATA_ROW;

                    $sheet->getStyle('A' . $first . ':' . $last . $lastRow)
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                        ->setVertical(Alignment::VERTICAL_CENTER);

                    // The name is the one column that reads better left-aligned.
                    $sheet->getStyle('B' . $first . ':B' . $lastRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                    // Half days make the day count a possible .5; OT is in hours
                    // and lands on quarters, so both keep their decimals.
                    $sheet->getStyle($this->letter($summary) . $first . ':' . $this->letter($summary + 1) . $lastRow)
                        ->getNumberFormat()->setFormatCode('0.00');

                    // Everything either side of the IN/OUT pair belongs to the
                    // employee, not to one of the two rows, so it merges down.
                    for ($index = 0; $index < count($this->rows); $index++) {
                        $top = self::FIRST_DATA_ROW + ($index * 2);
                        $bottom = $top + 1;

                        for ($column = 1; $column <= self::LEADING_COLUMNS - 1; $column++) {
                            $letter = $this->letter($column);
                            $sheet->mergeCells($letter . $top . ':' . $letter . $bottom);
                        }

                        for ($column = $summary; $column <= $this->totalColumns(); $column++) {
                            $letter = $this->letter($column);
                            $sheet->mergeCells($letter . $top . ':' . $letter . $bottom);
                        }

                        $sheet->getRowDimension($top)->setRowHeight(18);
                        $sheet->getRowDimension($bottom)->setRowHeight(18);
                    }
                }

                // Keeps the header and the employee's name visible while scrolling
                // sideways through a 31-day band.
                $sheet->freezePane($firstDay . self::FIRST_DATA_ROW);

                $sheet->getPageSetup()->setOrientation('landscape');
                $sheet->getPageSetup()->setFitToWidth(1);
                $sheet->getPageSetup()->setFitToHeight(0);
            },
        ];
    }
}
