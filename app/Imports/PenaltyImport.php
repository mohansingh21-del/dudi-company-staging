<?php

namespace App\Imports;

use App\Models\Penalty;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Row;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use App\Enums\RecoveryType;

class PenaltyImport implements
    OnEachRow,
    WithHeadingRow,
    WithValidation,
    SkipsEmptyRows
{
    public function onRow(Row $row)
    {
        $data = $row->toArray();

        DB::transaction(function () use ($data) {

            $penalty = Penalty::find($data['penalty_id']);

            if (!$penalty) {
                throw new \Exception(
                    'Penalty ID ' .
                        $data['penalty_id'] .
                        ' not found.'
                );
            }

            $penaltyDate = Carbon::parse(
                $data['date_of_damage_loss']
            );

            $penalty->update([
                /*
                 * Editable register data
                 */
                'employee_id' => $this->resolveEmployeeId(
                    $data['employee_code']
                ),

                'penalty_date' =>
                $penaltyDate->toDateString(),

                'month' =>
                $penaltyDate->month,

                'year' =>
                $penaltyDate->year,

                'recovery_type' =>
                strtolower(trim($data['recovery_type'])),

                'particulars' =>
                $data['particulars'] ?? null,

                'reason' =>
                $data['particulars'] ?? null,

                'amount' =>
                $data['amount'],

                'show_cause_issued' =>
                $this->parseBoolean(
                    $data['show_cause_issued']
                ),

                'explanation_heard_in_presence' =>
                $data['explanation_heard_in_presence'] ?? null,

                'number_of_installments' =>
                $data['number_of_installments'] ?? null,

                'first_month' =>
                $data['first_month'] ?? null,

                'first_year' =>
                $data['first_year'] ?? null,

                'last_month' =>
                $data['last_month'] ?? null,

                'last_year' =>
                $data['last_year'] ?? null,

                'date_of_complete_recovery' =>
                !empty($data['date_of_complete_recovery'])
                    ? Carbon::parse(
                        $data['date_of_complete_recovery']
                    )->toDateString()
                    : null,

                'remarks' =>
                $data['remarks'] ?? null,
            ]);

            /*
             * NOTICE:
             *
             * There is intentionally NO update here for:
             *
             * calculation_amount
             * calculation_recovery_type
             * calculation_particulars
             * calculation_date
             * calculation_number_of_installments
             * calculation_first_month
             * calculation_first_year
             * calculation_last_month
             * calculation_last_year
             */
        });
    }

    private function resolveEmployeeId($employeeCode): int
    {
        $employee = Employee::where(
            'employee_code',
            trim($employeeCode)
        )->first();

        if (!$employee) {
            throw new \Exception(
                'Employee with code ' .
                    $employeeCode .
                    ' not found.'
            );
        }

        return $employee->id;
    }

    private function parseBoolean($value): bool
    {
        return in_array(
            strtolower(trim((string) $value)),
            ['yes', 'true', '1'],
            true
        );
    }

    public function rules(): array
    {
        return [
            'penalty_id' => [
                'required',
                'integer',
                'exists:penalties,id',
            ],

            'employee_code' => [
                'required',
                'string',
                'exists:employees,employee_code',
            ],

            'employee_name' => [
                'nullable',
                'string',
            ],

            'recovery_type' => [
                'required',
                Rule::in(
                    RecoveryType::values()
                ),
            ],

            'particulars' => [
                'required',
                'string',
            ],

            'date_of_damage_loss' => [
                'required',
                'date',
            ],

            'amount' => [
                'required',
                'numeric',
                'min:0.01',
            ],

            'show_cause_issued' => [
                'required',
            ],

            'explanation_heard_in_presence' => [
                'nullable',
                'string',
            ],

            'number_of_installments' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'first_month' => [
                'nullable',
                'integer',
                'between:1,12',
            ],

            'first_year' => [
                'nullable',
                'integer',
                'between:1900,2200',
            ],

            'last_month' => [
                'nullable',
                'integer',
                'between:1,12',
            ],

            'last_year' => [
                'nullable',
                'integer',
                'between:1900,2200',
            ],

            'date_of_complete_recovery' => [
                'nullable',
                'date',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ];
    }
}
