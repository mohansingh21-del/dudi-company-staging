<?php

namespace App\Imports;

use App\Models\Leave;
use App\Models\Employee;
use App\Models\LeaveType;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class LeaveImport implements ToCollection, WithHeadingRow
{
    protected $errors = [];

   public function collection(Collection $rows)
{
    foreach ($rows as $index => $row) {

        $employee = Employee::where(
            'employee_code',
            $row['employee_code']
        )->first();

        if (!$employee) {
            $this->errors[] = [
                'row' => $index + 2,
                'message' => 'Employee Code not found: '.$row['employee_code']
            ];
            continue;
        }

        $leaveType = null;

        if (!empty($row['leave_type'])) {

            $leaveType = LeaveType::where(
                'name',
                $row['leave_type']
            )->first();

            if (!$leaveType) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'message' => 'Leave Type not found: '.$row['leave_type']
                ];
                continue;
            }
        }
$existingLeave = Leave::where('employee_id', $employee->id)
    ->where(function ($query) use ($row) {

        $query->whereBetween('from_date', [
                $row['from_date'],
                $row['to_date']
            ])
            ->orWhereBetween('to_date', [
                $row['from_date'],
                $row['to_date']
            ])
            ->orWhere(function ($q) use ($row) {

                $q->where('from_date', '<=', $row['from_date'])
                  ->where('to_date', '>=', $row['to_date']);
            });
    })
    ->exists();

if ($existingLeave) {

    $this->errors[] = [
        'row' => $index + 2,
        'message' =>
            "Leave already exists for Employee {$employee->employee_code} between {$row['from_date']} and {$row['to_date']}."
    ];

    continue;
}
        Leave::create([
            'employee_id'   => $employee->id,
            'leave_type_id' => $leaveType ? $leaveType->id : null,
            'from_date'     => $row['from_date'],
            'to_date'       => $row['to_date'],
            'reason'        => $row['reason'] ?? null,
            'status'        => $row['status'] ?? 'pending',
        ]);
    }
}

    public function getErrors()
    {
        return $this->errors;
    }
}