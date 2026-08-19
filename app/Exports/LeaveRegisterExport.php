<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Form E — the leave with wages register — as a printable spreadsheet.
 *
 * The sheet has 21 physical columns, not 25. The printed form numbers its
 * columns 1-20 and then jumps straight to 25 for Remarks; 21-24 do not exist
 * on the form at all. The number row reproduces that jump rather than closing
 * the gap, because the numbering is what makes the sheet recognisable as Form E.
 *
 * Compensatory Rest is five columns wide and every other block is four — it
 * alone carries column 6, "Rest Not Allowed", a compliance count of weeks the
 * employee was denied a weekly rest. Anything laying this form out must handle
 * that asymmetry; treating all four blocks as equal width shifts every column
 * after the first block.
 */
class LeaveRegisterExport implements FromArray, WithEvents, WithTitle, WithColumnWidths, WithStrictNullComparison
{
    // WithStrictNullComparison keeps a calculated 0 as a written 0 rather than
    // letting the writer blank the cell — on a leave register the difference
    // between "no leave taken" and "nothing recorded" is the whole point.

    /** Spreadsheet rows the fixed header occupies before any data. */
    protected const HEADER_ROWS = 5;

    /** The first spreadsheet row a register line lands on. */
    protected const FIRST_DATA_ROW = 6;

    /** The last column — Remarks, printed as column 25. */
    protected const LAST_COLUMN = 'U';

    /** Form E shaped rows, as returned by LeaveRegisterReportRow::toFormE(). */
    protected array $rows;

    protected int $year;

    protected string $label;

    public function __construct(array $rows, int $year, string $label)
    {
        $this->rows = $rows;
        $this->year = $year;
        $this->label = $label;
    }

    /** Labels that span the block band and the sub-label row beneath it. */
    protected const OUTER_LABELS = [
        'A' => 'S. No. In Employee Register',
        'B' => 'Name',
        'C' => 'No. of days worked in the Year',
        'U' => 'Remarks',
    ];

    /** The four block bands: first column, last column, printed title. */
    protected const BANDS = [
        ['D', 'H', 'Details of Compensatory Rest'],
        ['I', 'L', 'Details of Earned Leave'],
        ['M', 'P', 'Details of Medical Leave'],
        ['Q', 'T', 'Details of Other Leave'],
    ];

    /** Sub-labels sitting under the bands. Comp Rest is the five-wide one. */
    protected const BLOCK_LABELS = [
        'D' => 'Opening Balance', 'E' => 'Added', 'F' => 'Rest Not Allowed',
        'G' => 'Rest Availed', 'H' => 'Closing Balance',
        'I' => 'Opening Balance', 'J' => 'Added', 'K' => 'Leave Availed', 'L' => 'Closing Balance',
        'M' => 'Opening Balance', 'N' => 'Added', 'O' => 'Leave Availed', 'P' => 'Closing Balance',
        'Q' => 'Opening Balance', 'R' => 'Added', 'S' => 'Leave Availed', 'T' => 'Closing Balance',
    ];

    /**
     * The printed column numbers, in physical column order. Note the jump from
     * 20 to 25 — Form E has no columns 21-24.
     */
    protected const PRINTED_NUMBERS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 25];

    public function title(): string
    {
        return substr($this->label, 0, 31);
    }

    public function array(): array
    {
        // Rows 1-5 are written by the AfterSheet hook so they can be merged and
        // styled. The placeholders must be full-width rows of empty strings, not
        // empty arrays: fromArray() does not advance the row pointer for an empty
        // array, which would slide the data up under the header.
        $blank = array_fill(0, 21, '');

        $rows = [
            array_replace($blank, [
                0 => 'Name of Establishment',
                7 => 'Name of Owner',
                15 => 'LIN',
            ]),
            array_replace($blank, [7 => 'For the Year', 9 => $this->year]),
            $blank,
            $blank,
            $blank,
        ];

        foreach ($this->rows as $form) {
            $rows[] = [
                $form['employee_code'],                 // 1
                $form['name'],                          // 2
                $form['days_worked'],                   // 3

                $form['comp_rest_opening'],             // 4
                $form['comp_rest_added'],               // 5
                $form['comp_rest_not_allowed'],         // 6
                $form['comp_rest_availed'],             // 7
                $form['comp_rest_closing'],             // 8

                $form['earned_opening'],                // 9
                $form['earned_added'],                  // 10
                $form['earned_availed'],                // 11
                $form['earned_closing'],                // 12

                $form['medical_opening'],               // 13
                $form['medical_added'],                 // 14
                $form['medical_availed'],               // 15
                $form['medical_closing'],               // 16

                $form['other_opening'],                 // 17
                $form['other_added'],                   // 18
                $form['other_availed'],                 // 19
                $form['other_closing'],                 // 20

                $form['remarks'] ?? '',                 // 25
            ];
        }

        return $rows;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 8,  'B' => 24, 'C' => 12,
            'D' => 10, 'E' => 9, 'F' => 11, 'G' => 10, 'H' => 10,
            'I' => 10, 'J' => 9, 'K' => 10, 'L' => 10,
            'M' => 10, 'N' => 9, 'O' => 10, 'P' => 10,
            'Q' => 10, 'R' => 9, 'S' => 10, 'T' => 10,
            'U' => 18,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last = self::LAST_COLUMN;
                $lastRow = max(self::HEADER_ROWS, self::FIRST_DATA_ROW + count($this->rows) - 1);

                // ── Establishment line, then the year ──
                $sheet->mergeCells('B1:G1');
                $sheet->mergeCells('I1:O1');
                $sheet->mergeCells('Q1:U1');
                $sheet->mergeCells('J2:P2');
                $sheet->getStyle('A1:' . $last . '2')->getFont()->setBold(true);

                foreach (['B1:G1', 'I1:O1', 'Q1:U1', 'J2:P2'] as $range) {
                    $sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
                }

                $sheet->getStyle('J2:P2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // ── Block bands over row 3 ──
                foreach (self::BANDS as [$from, $to, $title]) {
                    $sheet->setCellValue($from . '3', $title);
                    $sheet->mergeCells($from . '3:' . $to . '3');
                }

                // ── Labels that span the band row and the one beneath it ──
                foreach (self::OUTER_LABELS as $column => $label) {
                    $sheet->setCellValue($column . '3', $label);
                    $sheet->mergeCells($column . '3:' . $column . '4');
                }

                foreach (self::BLOCK_LABELS as $column => $label) {
                    $sheet->setCellValue($column . '4', $label);
                }

                // ── The printed column numbers, 1-20 then 25 ──
                foreach (self::PRINTED_NUMBERS as $index => $number) {
                    $sheet->setCellValue([$index + 1, 5], $number);
                }

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
                $sheet->getRowDimension(4)->setRowHeight(38);

                // ── Grid ──
                $sheet->getStyle('A3:' . $last . $lastRow)->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                if (count($this->rows) > 0) {
                    $first = self::FIRST_DATA_ROW;

                    // Half days make every figure on this form a possible .5, so
                    // one decimal throughout rather than whole days.
                    $sheet->getStyle('C' . $first . ':T' . $lastRow)
                        ->getNumberFormat()->setFormatCode('0.0');

                    $sheet->getStyle('A' . $first . ':' . $last . $lastRow)
                        ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                    $sheet->getStyle('A' . $first . ':A' . $lastRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle('C' . $first . ':T' . $lastRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                // Keeps the header and the employee's name visible while scrolling.
                $sheet->freezePane('C' . self::FIRST_DATA_ROW);
                $sheet->getPageSetup()->setOrientation('landscape');
            },
        ];
    }
}
