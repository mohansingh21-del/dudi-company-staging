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
 * The Salary Overview listing as a spreadsheet: one line per month of a year,
 * with the year's totals underneath.
 *
 * A summary only — no employee rows. Form B for a single month is a different
 * sheet altogether, produced by WageRegisterExport.
 *
 * A month with no register prints "-" in its figure columns rather than 0. The
 * register was never filed, which is not the same as a month where nobody
 * earned anything, and a 0 would total up as though it were.
 */
class WageRegisterSummaryExport implements FromArray, WithEvents, WithTitle, WithColumnWidths, WithStrictNullComparison
{
    /** Spreadsheet rows the title and column labels occupy before any data. */
    protected const HEADER_ROWS = 3;

    /** The first spreadsheet row a month lands on. */
    protected const FIRST_DATA_ROW = 4;

    protected const LABELS = [
        'A' => 'Month',
        'B' => 'Total Employees',
        'C' => 'Total Gross Salary',
        'D' => 'Total Deductions',
        'E' => 'Total Net Salary',
        'F' => 'Status',
    ];

    /** Month rows as built by WageRegisterController::monthRows(). */
    protected array $months;

    protected int $year;

    public function __construct(array $months, int $year)
    {
        $this->months = $months;
        $this->year = $year;
    }

    public function title(): string
    {
        return 'Salary Overview ' . $this->year;
    }

    public function array(): array
    {
        // Rows 1-3 are written by the AfterSheet hook so they can be merged and
        // styled. The placeholders must be full-width rows of empty strings, not
        // empty arrays: fromArray() does not advance the row pointer for an empty
        // array, which would let the header overwrite the first months.
        $blank = array_fill(0, 6, '');

        $rows = [$blank, $blank, $blank];

        foreach ($this->months as $month) {
            $generated = $month['status'] === 'generated';

            $rows[] = [
                $month['month_label'],
                $generated ? $month['employee_count'] : '-',
                $generated ? $month['total_earnings'] : '-',
                $generated ? $month['total_deductions'] : '-',
                $generated ? $month['total_net'] : '-',
                $generated ? 'GENERATED' : 'NOT GENERATED',
            ];
        }

        // Only the generated months contribute — an ungenerated one has no
        // figures to add, so counting it would understate nothing but the total
        // months it was drawn from.
        $generated = collect($this->months)->where('status', 'generated');

        $rows[] = [
            'Total (' . $generated->count() . ' generated month'
                . ($generated->count() === 1 ? '' : 's') . ')',
            '',
            round($generated->sum('total_earnings'), 2),
            round($generated->sum('total_deductions'), 2),
            round($generated->sum('total_net'), 2),
            '',
        ];

        return $rows;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22, 'B' => 16, 'C' => 20,
            'D' => 18, 'E' => 20, 'F' => 18,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // The totals line sits on the row after the last month.
                $totalRow = self::FIRST_DATA_ROW + count($this->months);
                $lastDataRow = $totalRow - 1;

                // ── Title ──
                $sheet->setCellValue('A1', 'Salary Overview — ' . $this->year);
                $sheet->mergeCells('A1:F1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // ── Column labels ──
                foreach (self::LABELS as $column => $label) {
                    $sheet->setCellValue($column . self::HEADER_ROWS, $label);
                }

                $header = $sheet->getStyle('A3:F3');
                $header->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                $header->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF31859C');
                $header->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);

                $sheet->getRowDimension(3)->setRowHeight(26);

                // ── Grid ──
                $sheet->getStyle('A3:F' . $totalRow)->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                if (count($this->months) > 0) {
                    // Two decimals on the money columns. A "-" is text and is
                    // left alone by the format, so an unfiled month still reads
                    // as blank rather than 0.00.
                    $sheet->getStyle('C' . self::FIRST_DATA_ROW . ':E' . $lastDataRow)
                        ->getNumberFormat()->setFormatCode('#,##0.00');

                    $sheet->getStyle('B' . self::FIRST_DATA_ROW . ':B' . $lastDataRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle('F' . self::FIRST_DATA_ROW . ':F' . $lastDataRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                // ── Totals line ──
                $totals = $sheet->getStyle('A' . $totalRow . ':F' . $totalRow);
                $totals->getFont()->setBold(true);
                $totals->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFEFEFEF');
                $sheet->getStyle('C' . $totalRow . ':E' . $totalRow)
                    ->getNumberFormat()->setFormatCode('#,##0.00');

                // Keeps the labels visible while scrolling.
                $sheet->freezePane('A' . self::FIRST_DATA_ROW);
            },
        ];
    }
}
