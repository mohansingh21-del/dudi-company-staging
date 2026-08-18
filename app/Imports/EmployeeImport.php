<?php

namespace App\Imports;

use App\Models\Employee;
use App\Models\Department;
use App\Models\Role;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

/**
 * Bulk employee import. Accepts every column the single-employee form accepts,
 * apart from the photo and specimen signature, which are file uploads and have
 * no spreadsheet equivalent.
 *
 * Enum columns are spelled loosely in real spreadsheets ("Semi Skilled",
 * "Fixed Term"), so each is normalised through a map. A value that does not
 * normalise fails validation with its row and column rather than importing as
 * null, which is how skill_category used to go missing silently.
 */
class EmployeeImport implements ToCollection, WithHeadingRow, WithValidation
{
    private const PLACES = [
        'underground' => 'underground',
        'ug' => 'underground',
        'opencast' => 'opencast',
        'cast' => 'opencast',
        'oc' => 'opencast',
        'surface' => 'surface',
    ];

    private const SKILLS = [
        'highlyskilled' => 'highly_skilled',
        'highskilled' => 'highly_skilled',
        'skilled' => 'skilled',
        'semiskilled' => 'semi_skilled',
        'unskilled' => 'unskilled',
    ];

    private const TYPES = [
        'permanent' => 'permanent',
        'probationary' => 'probationary',
        'probation' => 'probationary',
        'temporary' => 'temporary',
        'temp' => 'temporary',
        'contract' => 'contract',
        'apprentice' => 'apprentice',
        'fixedterm' => 'fixed_term',
        'casual' => 'casual',
    ];

    private const GENDERS = [
        'male' => 'male',
        'm' => 'male',
        'female' => 'female',
        'f' => 'female',
        'other' => 'other',
    ];

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

            $site = !empty($row['site'])
                ? Site::where('id', $row['site'])
                ->orWhere('site_name', $row['site'])
                ->first()
                : null;

            $dateOfExit = !empty($row['date_of_exit'])
                ? $this->parseDate($row['date_of_exit'])
                : null;

            Employee::create([

                'employee_code' => $row['employee_code'],
                'name' => $row['name'],
                'surname' => $row['surname'] ?? null,
                'father_name' => $row['father_name'] ?? null,

                'dob' => !empty($row['dob'])
                    ? $this->parseDate($row['dob'])
                    : null,

                'nationality' => $row['nationality'] ?? 'Indian',
                'education_level' => $row['education_level'] ?? null,
                'identification_mark' => $row['identification_mark'] ?? null,

                'gender' => $this->normalise($row['gender'] ?? null, self::GENDERS),
                'mobile' => $row['mobile'] ?? null,
                'address' => $row['address'] ?? null,
                'permanent_address' => $row['permanent_address'] ?? null,
                'emergency_contact' => $row['emergency_contact'] ?? null,

                'joining_date' => !empty($row['joining_date'])
                    ? $this->parseDate($row['joining_date'])
                    : null,

                'service_book_no' => $row['service_book_no'] ?? null,
                'employee_type' => $this->normalise($row['employee_type'] ?? null, self::TYPES) ?? 'permanent',

                'place_of_employment' => $this->normalise($row['place_of_employment'] ?? null, self::PLACES),
                'skill_category' => $this->normalise($row['skill_category'] ?? null, self::SKILLS),

                'department_id' => $department ? $department->id : null,
                'designation_id' => $designation ? $designation->id : null,
                'site_id' => $site ? $site->id : null,

                'date_of_exit' => $dateOfExit,
                'reason_for_exit' => $row['reason_for_exit'] ?? null,
                'remarks' => $row['remarks'] ?? null,

                // An employee with a date of exit is never active, matching
                // the single-employee form.
                'is_active' => $dateOfExit ? 0 : (int) ($row['status'] ?? 1),

                'relay_id' => $relay ? $relay->id : null,
            ]);
        }

        $this->linkSupervisors($rows);
    }

    /**
     * Supervisors are linked after every row exists, so a supervisor may appear
     * anywhere in the file — above or below the people reporting to them.
     * Validation cannot do this check, as it runs before any row is written.
     */
    private function linkSupervisors(Collection $rows): void
    {
        $unresolved = [];

        foreach ($rows as $index => $row) {
            if (empty($row['employee_code']) || empty($row['supervisor'])) {
                continue;
            }

            $supervisor = Employee::where('employee_code', $row['supervisor'])
                ->orWhere('name', $row['supervisor'])
                ->first();

            if (!$supervisor) {
                // +2 puts the number back in spreadsheet terms: 1-based, past the header.
                $unresolved[] = 'row ' . ($index + 2) . ': "' . $row['supervisor'] . '"';
                continue;
            }

            Employee::where('employee_code', $row['employee_code'])
                ->update(['supervisor_id' => $supervisor->id]);
        }

        if ($unresolved) {
            throw new \Exception(
                'Supervisor not found for ' . implode(', ', $unresolved)
                . '. Use the supervisor\'s employee code or exact name.'
            );
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

            '*.reason_for_exit.required_with' =>
                'Reason for exit is required when a date of exit is given.',
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
            '*.surname' => 'nullable|string|max:255',

            '*.father_name' => 'nullable|string|max:255',

            '*.dob' => 'nullable',

            '*.nationality' => 'nullable|string|max:100',
            '*.education_level' => 'nullable|string|max:255',
            '*.identification_mark' => 'nullable|string|max:255',

            '*.gender' => ['nullable', $this->enumRule(self::GENDERS, 'Gender')],

            '*.mobile' => 'nullable|max:15|unique:employees,mobile',

            '*.address' => 'nullable|string',
            '*.permanent_address' => 'nullable|string',

            '*.emergency_contact' => 'nullable|max:15',

            '*.joining_date' => 'required',

            '*.service_book_no' => 'nullable|string|max:255',

            '*.employee_type' => ['nullable', $this->enumRule(self::TYPES, 'Employee type')],
            '*.skill_category' => ['nullable', $this->enumRule(self::SKILLS, 'Skill category')],
            '*.place_of_employment' => ['nullable', $this->enumRule(self::PLACES, 'Place of employment')],

            '*.department' => ['nullable', $this->lookupRule(Department::class, 'name', 'Department')],
            '*.designation' => ['nullable', $this->lookupRule(Role::class, 'name', 'Designation')],
            '*.site' => ['nullable', $this->lookupRule(Site::class, 'site_name', 'Site')],

            '*.date_of_exit' => 'nullable',
            '*.reason_for_exit' => 'nullable|required_with:*.date_of_exit|string|max:255',

            '*.remarks' => 'nullable|string',

            '*.status' => 'nullable|in:0,1',
        ];
    }

    /**
     * Accepts any spelling the matching map understands, so the import fails
     * before writing anything rather than storing a silent null.
     */
    private function enumRule(array $map, string $label): \Closure
    {
        return function ($attribute, $value, $fail) use ($map, $label) {
            if ($value === null || $value === '') {
                return;
            }

            if ($this->normalise($value, $map) === null) {
                $fail("{$label} \"{$value}\" is not recognised. Allowed: "
                    . implode(', ', array_unique(array_values($map))) . '.');
            }
        };
    }

    /**
     * Names and codes are resolved to ids at write time; a value that matches
     * nothing would import as null, so reject it here instead.
     */
    private function lookupRule(string $model, string $column, string $label, string $alt = null): \Closure
    {
        return function ($attribute, $value, $fail) use ($model, $column, $label, $alt) {
            if ($value === null || $value === '') {
                return;
            }

            $query = $model::where('id', $value)->orWhere($column, $value);

            if ($alt) {
                $query->orWhere($alt, $value);
            }

            if (!$query->exists()) {
                $fail("{$label} \"{$value}\" was not found.");
            }
        };
    }

    /**
     * Spreadsheets spell these columns loosely — "Under Ground", "Semi Skilled",
     * "Fixed-Term". Punctuation and case are stripped before matching.
     */
    private function normalise($value, array $map): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalised = preg_replace('/[^a-z]/', '', strtolower((string) $value));

        return $map[$normalised] ?? null;
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
