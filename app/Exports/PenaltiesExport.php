<?php

namespace App\Exports;

use App\Models\Penalty;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class PenaltiesExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStrictNullComparison
{
    public function __construct(
        protected array $filters = []
    ) {}

    public function query()
    {
        $query = Penalty::query()
            ->with('employee');

        if (!empty($this->filters['employee_id'])) {
            $query->where(
                'employee_id',
                $this->filters['employee_id']
            );
        }

        if (!empty($this->filters['recovery_type'])) {
            $query->where(
                'recovery_type',
                $this->filters['recovery_type']
            );
        }

        if (!empty($this->filters['month'])) {
            $query->where(
                'month',
                $this->filters['month']
            );
        }

        if (!empty($this->filters['year'])) {
            $query->where(
                'year',
                $this->filters['year']
            );
        }

        if (!empty($this->filters['penalty_date'])) {
            $query->whereDate(
                'penalty_date',
                $this->filters['penalty_date']
            );
        }

        return $query->latest('id');
    }

    public function headings(): array
    {
        return [
            'Penalty ID',
            'Employee Code',
            'Employee Name',
            'Recovery Type',
            'Particulars',
            'Date of Damage/Loss',
            'Amount',
            'Show Cause Issued',
            'Explanation Heard in Presence Of',
            'Number of Installments',
            'First Month',
            'First Year',
            'Last Month',
            'Last Year',
            'Date of Complete Recovery',
            'Remarks',
        ];
    }

    public function map($penalty): array
    {
        return [
            $penalty->id,

            optional($penalty->employee)->employee_code,

            optional($penalty->employee)->name,

            $penalty->recovery_type,

            $penalty->particulars,

            $penalty->penalty_date
                ? $penalty->penalty_date->format('Y-m-d')
                : null,

            $penalty->amount,

            $penalty->show_cause_issued
                ? 'Yes'
                : 'No',

            $penalty->explanation_heard_in_presence,

            $penalty->number_of_installments,

            $penalty->first_month,

            $penalty->first_year,

            $penalty->last_month,

            $penalty->last_year,

            $penalty->date_of_complete_recovery
                ? $penalty->date_of_complete_recovery->format('Y-m-d')
                : null,

            $penalty->remarks,
        ];
    }
}
