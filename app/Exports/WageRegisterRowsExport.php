<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * A frozen register's rows as flat data — one header line, one line per
 * employee. This is the "Export CSV" download from the month detail screen.
 *
 * Deliberately not the printed Form B layout. Form B's header is a banded,
 * merged, three-row affair (see WageRegisterExport), which a CSV cannot express
 * and which nothing can parse. So the columns are flattened to a single header
 * row, and the two figures Form B has no column for are appended at the end
 * rather than dropped:
 *
 *   Absence Deduction     — pay never earned, taken off the net directly
 *   Unrecovered Deduction — deductions that outran earnings and were written off
 *
 * Without those two, Net Payment does not reconcile against Total Earnings minus
 * Total Deductions and the sheet looks wrong when it is not.
 *
 * Employee Code and Skill Category ride along too. Neither is a printed column,
 * but this is a data export and the code is the only stable key back to an
 * employee — names are neither unique nor stable.
 */
class WageRegisterRowsExport implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison
{
    // WithStrictNullComparison matters here: without it the writer treats a 0 as
    // null and leaves the cell blank, which would erase the difference between a
    // deduction calculated as 0 and a column with no source at all.

    /** Form B shaped rows, as returned by WageRegisterReportRow::toFormB(). */
    protected array $rows;

    protected string $label;

    public function __construct(array $rows, string $label)
    {
        $this->rows = $rows;
        $this->label = $label;
    }

    public function title(): string
    {
        return substr($this->label, 0, 31);
    }

    public function headings(): array
    {
        return [
            'S. No.',
            'Employee Code',
            'Name',
            'Skill Category',
            'Rate of Wage',
            'No. of days worked',
            'Overtime hours Worked',
            'Basic',
            'Special basic',
            'Dearness Allowance',
            'Payments Overtime',
            'HRA',
            'Other Earnings',
            'Total Earnings',
            'PF',
            'ESIC',
            'Society',
            'Income Tax',
            'Insurance',
            'Other Deductions',
            'Recoveries',
            'Total Deductions',
            'Net Payment',
            'Employer Share PF Welfare Fund',
            'Receipt by Employee/Bank Transaction ID',
            'Date of Payment',
            'Remarks',

            // Not Form B columns — see the class note.
            'Absence Deduction',
            'Unrecovered Deduction',
        ];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->rows as $form) {
            $rows[] = [
                $form['serial_no'],
                $form['employee_code'],
                $form['name'],
                $form['skill_category'],
                $this->cell($form['rate_of_wage']),
                $this->cell($form['days_worked']),
                $this->cell($form['overtime_hours']),
                $this->cell($form['basic']),
                $this->cell($form['special_basic']),
                $this->cell($form['dearness_allowance']),
                $this->cell($form['overtime_payment']),
                $this->cell($form['hra']),
                $this->cell($form['other_earnings']),
                $this->cell($form['total_earnings']),
                $this->cell($form['pf_deduction']),
                $this->cell($form['esic_deduction']),
                $this->cell($form['society_deduction']),
                $this->cell($form['income_tax']),
                $this->cell($form['insurance']),
                $this->cell($form['other_deductions']),
                $this->cell($form['recoveries']),
                $this->cell($form['total_deductions']),
                $this->cell($form['net_payment']),
                $this->cell($form['employer_pf_share']),
                $form['payment_reference'],
                $form['payment_date'],
                $form['remarks'],
                $this->cell($form['absence_deduction']),
                $this->cell($form['unrecovered_deduction']),
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
}
