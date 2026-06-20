<?php

namespace App\Imports;

use App\Models\Employee;
use App\Models\Department;
use App\Models\Role;
use App\Models\Site;
use App\Models\Employee as Supervisor;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class EmployeeImport implements ToCollection, WithHeadingRow, WithValidation
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
///dd($rows->first());
            // skip empty rows
            if (empty($row['employee_code'])) {
                continue;
            }

            $department = !empty($row['department'])
                ? Department::where('id', $row['department'])
                ->orWhere('name', $row['department'])
                ->first()
                : null;

            $designation = !empty($row['designation'])
                ? Role::where('id', $row['designation'])
                ->orWhere('name', $row['designation'])
                ->first()
                : null;

            Employee::create([

                'employee_code' => $row['employee_code'],
                'name' => $row['name'],
                'father_name' => $row['father_name'] ?? null,

                'dob' => !empty($row['dob'])
                    ? Carbon::createFromFormat('d/m/Y', $row['dob'])
                    : null,

                'gender' => $row['gender'] ?? null,
                'mobile' => $row['mobile'] ?? null,
                'address' => $row['address'] ?? null,
                'emergency_contact' => $row['emergency_contact'] ?? null,

                'joining_date' => !empty($row['joining_date'])
                    ? Carbon::createFromFormat('d/m/Y', $row['joining_date'])
                    : null,


              
                'department_id' => $department ? $department->id : null,
                'designation_id' => $designation ? $designation->id : null,


               
              
                'is_active' => (int) ($row['status'] ?? 1),

                'relay_shift' => $row['relay_shift'],
            ]);
        }
    }
      public function customValidationMessages()
{
    return [

        '*.employee_code.required' =>
            'Employee Code is required.',

        '*.employee_code.unique' =>
            'Employee Code already exists.',

        '*.name.required' =>
            'Employee Name is required.',

        '*.joining_date.required' =>
            'Joining Date is required.',

        '*.joining_date.date_format' =>
            'Joining Date must be in d/m/Y format.',

        '*.mobile.unique' =>
            'Mobile number already exists.',

       
       
    ];
}
    /* ===============================
        VALIDATION RULES
    =============================== */
    public function rules(): array
    {
        return [

            '*.employee_code' => 'required|string|max:255|unique:employees,employee_code',
            '*.name' => 'required|string|max:255',

            '*.father_name' => 'nullable|string|max:255',

            '*.dob' => 'nullable|date_format:d/m/Y',

            '*.gender' => 'nullable|in:male,female,other',

            '*.mobile' => 'nullable|max:15|unique:employees,mobile',

            '*.address' => 'nullable|string',

            '*.emergency_contact' => 'nullable|max:15',

            '*.joining_date' => 'required|date_format:d/m/Y',



            '*.basic_salary' => 'nullable|numeric|min:0',

            '*.daily_wage' => 'nullable|numeric|min:0',

            '*.pf_applicable' => 'nullable|boolean',

            '*.pf_number' => 'nullable|string|max:255',

            '*.bank_name' => 'nullable|string|max:255',

            '*.bank_account_number' => 'nullable|max:50',

            '*.ifsc_code' => 'nullable|string|max:20',

            '*.mess_deduction_applicable' => 'nullable|boolean',

            '*.status' => 'in:0,1',

            '*.other_deduction_appliacble' => 'nullable|boolean',

            '*.other_deduction' => 'nullable|numeric|min:0',
        ];
    }
}
