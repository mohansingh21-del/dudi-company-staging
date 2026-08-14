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
 * Form B as a working spreadsheet: the live calculated figures for a month, in
 * the printed layout, for the user to download, correct by hand and import back
 * before the register is generated.
 *
 * Column 1, "S. No. In Employee Register", carries the employee code — that is
 * literally what the column is, and it doubles as the key the import matches a
 * row back to an employee by. Names are neither unique nor stable, so nothing
 * else on the sheet can serve that purpose.
 *
 * The columns with no source in the system generate blank, which is the point:
 * those are the cells the user fills in. A figure the system did calculate is
 * written even when it is 0, so an untouched 0 stays distinguishable from a
 * cell nobody has filled in yet.
 */
class WageRegisterExport implements FromArray, WithEvents, WithTitle, WithColumnWidths, WithStrictNullComparison
{
    // WithStrictNullComparison matters here: without it the writer treats a 0 as
    // null and leaves the cell blank, which would erase the difference between a
    // deduction calculated as 0 and a column with no source at all.

    /** Spreadsheet rows the fixed header occupies before any data. */
    protected const HEADER_ROWS = 5;

    /** The first spreadsheet row a register line lands on. */
    protected const FIRST_DATA_ROW = 6;

    /** Form B shaped rows, as returned by WageRegisterReportRow::toFormB(). */
    protected array $rows;

    protected string $label;

    public function __construct(array $rows, string $label)
    {
        $this->rows = $rows;
        $this->label = $label;
    }

    /**
     * Columns 1-12 and 21-25 — labels that sit above the "Deduction" band and
     * are merged down through the band's own row.
     */
    protected const OUTER_LABELS = [
        'A' => 'S. No. In Employee Register',
        'B' => 'Name',
        'C' => 'Rate of Wage',
        'D' => 'No. of days worked',
        'E' => 'Overtime hours Worked',
        'F' => 'Basic',
        'G' => 'Special basic',
        'H' => 'Dearness Allowance',
        'I' => 'Payments Overtime',
        'J' => 'HRA',
        'K' => '*Others',
        'L' => 'Total',
        'U' => 'Net Payment',
        'V' => 'Employer Share PF Welfare Fund',
        'W' => 'Receipt by Employee/Bank Transaction ID',
        'X' => 'Date of Payment',
        'Y' => 'Remarks',
    ];

    /** Columns 13-20 — the labels sitting under the "Deduction" band. */
    protected const DEDUCTION_LABELS = [
        'M' => 'PF',
        'N' => 'ESIC',
        'O' => 'Society',
        'P' => 'Income Tax',
        'Q' => 'Insurance',
        'R' => 'Others',
        'S' => 'Recoveries',
        'T' => 'Total',
    ];

    public function title(): string
    {
        return substr($this->label, 0, 31);
    }

    public function array(): array
    {
        // Rows 1-5 are written by the AfterSheet hook so they can be merged and
        // styled. The placeholders must be full-width rows of empty strings, not
        // empty arrays: fromArray() does not advance the row pointer for an
        // empty array, which would slide the data up and let the header overwrite
        // the first employees.
        $blank = array_fill(0, 25, '');

        $rows = [
            array_replace($blank, [
                0 => 'Name of Establishment',
                5 => 'Name of Owner',
                11 => 'LIN',
            ]),
            $blank,
            $blank,
            $blank,
            $blank,
        ];

        foreach ($this->rows as $form) {
            $rows[] = [
                $form['employee_code'],                      // 1
                $form['name'],                               // 2
                $this->cell($form['rate_of_wage']),          // 3
                $this->cell($form['days_worked']),           // 4
                $this->cell($form['overtime_hours']),        // 5
                $this->cell($form['basic']),                 // 6
                $this->cell($form['special_basic']),         // 7
                $this->cell($form['dearness_allowance']),    // 8
                $this->cell($form['overtime_payment']),      // 9
                $this->cell($form['hra']),                   // 10
                $this->cell($form['other_earnings']),        // 11
                $this->cell($form['total_earnings']),        // 12
                $this->cell($form['pf_deduction']),          // 13
                $this->cell($form['esic_deduction']),        // 14
                $this->cell($form['society_deduction']),     // 15
                $this->cell($form['income_tax']),            // 16
                $this->cell($form['insurance']),             // 17
                $this->cell($form['other_deductions']),      // 18
                $this->cell($form['recoveries']),            // 19
                $this->cell($form['total_deductions']),      // 20
                $this->cell($form['net_payment']),           // 21
                $this->cell($form['employer_pf_share']),     // 22
                $form['payment_reference'],                  // 23
                $form['payment_date'],                       // 24
                $form['remarks'],                            // 25
            ];
        }

        return $rows;
    }

    /**
     * A null column stays an empty cell rather than becoming 0.
     */
    protected function cell($value)
    {
        return $value === null ? '' : $value;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 10, 'B' => 22, 'C' => 11, 'D' => 11, 'E' => 12,
            'F' => 12, 'G' => 11, 'H' => 12, 'I' => 12, 'J' => 11,
            'K' => 11, 'L' => 12, 'M' => 10, 'N' => 10, 'O' => 10,
            'P' => 10, 'Q' => 10, 'R' => 10, 'S' => 11, 'T' => 11,
            'U' => 12, 'V' => 14, 'W' => 20, 'X' => 13, 'Y' => 16,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = max(self::HEADER_ROWS, self::FIRST_DATA_ROW + count($this->rows) - 1);

                // ── Establishment line ──
                $sheet->mergeCells('B1:E1');
                $sheet->mergeCells('G1:K1');
                $sheet->mergeCells('M1:Q1');
                $sheet->getStyle('A1:Y1')->getFont()->setBold(true);
                $sheet->getStyle('B1:E1')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle('G1:K1')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle('M1:Q1')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

                // ── "Deduction" band over columns 13-20 ──
                $sheet->setCellValue('M3', 'Deduction');
                $sheet->mergeCells('M3:T3');

                // ── Column labels ──
                foreach (self::OUTER_LABELS as $column => $label) {
                    $sheet->setCellValue($column . '3', $label);
                    // Merged down so they line up with the deduction sub-labels.
                    $sheet->mergeCells($column . '3:' . $column . '4');
                }

                foreach (self::DEDUCTION_LABELS as $column => $label) {
                    $sheet->setCellValue($column . '4', $label);
                }

                // ── The printed column numbers, 1-25 ──
                foreach (range(1, 25) as $number) {
                    $sheet->setCellValue([$number, 5], $number);
                }

                // The form prints its header white-on-black.
                $header = $sheet->getStyle('A3:Y5');
                $header->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF000000');
                $header->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);

                $sheet->getRowDimension(3)->setRowHeight(34);
                $sheet->getRowDimension(4)->setRowHeight(20);

                // ── Grid ──
                $sheet->getStyle('A3:Y' . $lastRow)->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                if (count($this->rows) > 0) {
                    $data = 'A' . self::FIRST_DATA_ROW . ':Y' . $lastRow;

                    // Two decimals on the money columns. The format deliberately
                    // has no blank "zero" section: a calculated 0 must still read
                    // as 0.00, so it stays distinguishable from an empty cell
                    // where nothing was recorded at all.
                    $sheet->getStyle('C' . self::FIRST_DATA_ROW . ':V' . $lastRow)
                        ->getNumberFormat()->setFormatCode('#,##0.00');
                    $sheet->getStyle($data)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                    $sheet->getStyle('A' . self::FIRST_DATA_ROW . ':A' . $lastRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                // Keeps the header visible while scrolling a long register.
                $sheet->freezePane('C' . self::FIRST_DATA_ROW);
                $sheet->getPageSetup()->setOrientation('landscape');
            },
        ];
    }
}
