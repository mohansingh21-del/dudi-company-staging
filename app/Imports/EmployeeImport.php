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
            // skip empty rows
            if (empty($row['employee_code'])) {
                continue;
            }

            $relay = null;
            if (!empty($row['relay'])) {
                $relay = \App\Models\Relay::where('id', $row['relay'])
                    ->orWhere('name', $row['relay'])
                    ->first();
            } elseif (!empty($row['relay_shift'])) {
                $relayName = $row['relay_shift'];
                // Normalize legacy names
                if (strtolower($relayName) === 'relay_1') {
                    $relayName = 'Relay A';
                } elseif (strtolower($relayName) === 'relay_2') {
                    $relayName = 'Relay B';
                } elseif (strtolower($relayName) === 'relay_3') {
                    $relayName = 'Relay C';
                }
                $relay = \App\Models\Relay::where('name', $relayName)
                    ->orWhere('id', $row['relay_shift'])
                    ->first();
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
                    ? $this->parseDate($row['dob'])
                    : null,

                'gender' => $row['gender'] ?? null,
                'mobile' => $row['mobile'] ?? null,
                'address' => $row['address'] ?? null,
                'emergency_contact' => $row['emergency_contact'] ?? null,

                'joining_date' => !empty($row['joining_date'])
                    ? $this->parseDate($row['joining_date'])
                    : null,

                'place_of_employment' => $this->parsePlaceOfEmployment($row['place_of_employment'] ?? null),

                'department_id' => $department ? $department->id : null,
                'designation_id' => $designation ? $designation->id : null,

                'is_active' => (int) ($row['status'] ?? 1),

                'relay_id' => $relay ? $relay->id : null,
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

            '*.dob' => 'nullable',

            '*.gender' => 'nullable|in:male,female,other',

            '*.mobile' => 'nullable|max:15|unique:employees,mobile',

            '*.address' => 'nullable|string',

            '*.emergency_contact' => 'nullable|max:15',

            '*.joining_date' => 'required',

            '*.status' => 'nullable|in:0,1',
        ];
    }

    /**
     * Spreadsheets spell this column loosely — "Under Ground", "Open Cast",
     * "OC". Anything unrecognised is left null rather than guessed at.
     */
    private function parsePlaceOfEmployment($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $normalised = preg_replace('/[^a-z]/', '', strtolower((string) $value));

        $places = [
            'underground' => 'underground',
            'ug' => 'underground',
            'opencast' => 'opencast',
            'cast' => 'opencast',
            'oc' => 'opencast',
            'surface' => 'surface',
        ];

        return $places[$normalised] ?? null;
    }

    private function parseDate($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value))->startOfDay();
        }

        $value = trim((string) $value);

        $formats = [
            'd/m/Y',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'Y-m-d',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'd-m-Y',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'm/d/Y',
            'm/d/Y H:i:s',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed !== false) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable $ex) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            throw new \Exception("Invalid date format: {$value}");
        }
    }
}

