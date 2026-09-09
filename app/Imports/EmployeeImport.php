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

            $relay = !empty($row['relay'])
                ? $this->findRelay($row['relay'])
                : (!empty($row['relay_shift']) ? $this->findRelay($row['relay_shift']) : null);

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

            '*.site.required' =>
                'Site is required.',

            '*.skill_category.required' =>
                'Skill category is required.',

            '*.mobile.unique' =>
                'Mobile number already exists.',

            '*.employee_code.distinct' =>
                'Employee Code is repeated in this file.',

            '*.mobile.distinct' =>
                'Mobile number is repeated in this file.',

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

            // `unique` only looks at the table, so `distinct` is what catches a
            // code or mobile repeated twice inside the same spreadsheet.
            '*.employee_code' => 'required|string|max:255|distinct|unique:employees,employee_code',
            '*.name' => 'required|string|max:255',
            '*.surname' => 'nullable|string|max:255',

            '*.father_name' => 'nullable|string|max:255',

            '*.dob' => 'nullable',

            '*.nationality' => 'nullable|string|max:100',
            '*.education_level' => 'nullable|string|max:255',
            '*.identification_mark' => 'nullable|string|max:255',

            '*.gender' => ['nullable', $this->enumRule(self::GENDERS, 'Gender')],

            '*.mobile' => 'nullable|max:15|distinct|unique:employees,mobile',

            '*.address' => 'nullable|string',
            '*.permanent_address' => 'nullable|string',

            '*.emergency_contact' => 'nullable|max:15',

            '*.joining_date' => 'required',

            '*.service_book_no' => 'nullable|string|max:255',

            '*.employee_type' => ['nullable', $this->enumRule(self::TYPES, 'Employee type')],
            '*.skill_category' => ['required', $this->enumRule(self::SKILLS, 'Skill category')],
            '*.place_of_employment' => ['nullable', $this->enumRule(self::PLACES, 'Place of employment')],

            '*.department' => ['nullable', $this->lookupRule(Department::class, 'name', 'Department')],
            '*.designation' => ['nullable', $this->lookupRule(Role::class, 'name', 'Designation')],
            '*.site' => ['required', $this->lookupRule(Site::class, 'site_name', 'Site')],
            '*.relay' => ['nullable', $this->relayRule()],
            '*.relay_shift' => ['nullable', $this->relayRule()],

            '*.date_of_exit' => 'nullable',
            '*.reason_for_exit' => 'nullable|required_with:*.date_of_exit|string|max:255',

            '*.remarks' => 'nullable|string',

            '*.status' => 'nullable|in:0,1',
        ];
    }

    /**
     * An unknown relay used to import as null, the same silent failure
     * skill_category had. Reject it with the row and column instead.
     */
    private function relayRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if ($value === null || $value === '') {
                return;
            }

            if (!$this->findRelay($value)) {
                $fail("Relay \"{$value}\" was not found. Allowed: "
                    . \App\Models\Relay::pluck('name')->implode(', ') . '.');
            }
        };
    }

    /**
     * Columns whose value must stay as the sheet gave it: a date cell arrives
     * as an Excel serial or a DateTime, and parseDate needs it that way.
     */
    private const RAW_COLUMNS = ['dob', 'joining_date', 'date_of_exit'];

    /**
     * Checks that need the whole row, which per-column rules cannot see:
     * date formats and the exit-after-joining ordering.
     */
    public function withValidator($validator)
    {
        // A numeric-looking cell ("122", a mobile number) reaches us as an int,
        // which fails `string` and makes `max:15` compare sizes instead of
        // lengths. prepareForValidation is not called on the ToCollection path,
        // so the cast has to happen here, before the rules run.
        $validator->setData($this->stringifyCells($validator->getData()));

        $validator->after(function ($validator) {

            foreach ($validator->getData() as $index => $row) {

                if (empty($row['employee_code'])) {
                    continue;
                }

                foreach (['dob', 'joining_date', 'date_of_exit'] as $field) {
                    if (!empty($row[$field]) && !$this->tryDate($row[$field])) {
                        $label = ucfirst(str_replace('_', ' ', $field));
                        $validator->errors()->add(
                            "{$index}.{$field}",
                            "{$label} \"{$row[$field]}\" is not a valid date. Use d/m/Y, e.g. 01/04/2024."
                        );
                    }
                }

                if (!empty($row['date_of_exit']) && !empty($row['joining_date'])) {

                    $exit = $this->tryDate($row['date_of_exit']);
                    $join = $this->tryDate($row['joining_date']);

                    if ($exit && $join && $exit->lt($join)) {
                        $validator->errors()->add(
                            "{$index}.date_of_exit",
                            'Date of exit cannot be before the joining date.'
                        );
                    }
                }
            }
        });
    }

    /**
     * Numbers become strings so the text rules see text. Dates, blanks and
     * anything that is not a plain number are left exactly as they were.
     */
    private function stringifyCells(array $rows): array
    {
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $column => $value) {
                if (in_array($column, self::RAW_COLUMNS, true)) {
                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $rows[$index][$column] = (string) $value;
                }
            }
        }

        return $rows;
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
     * Relay by name or id. The sheet's literal value wins: sites that named
     * their relays Relay_1/2/3 must not be rewritten to the legacy Relay A/B/C
     * spellings, which is what made a real relay import as "not found".
     * Shared by the validation rule and the write so they cannot disagree.
     */
    private function findRelay($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($relay = $this->relayByName($value)) {
            return $relay;
        }

        if (ctype_digit($value) && $relay = \App\Models\Relay::find((int) $value)) {
            return $relay;
        }

        $legacy = [
            'relay_1' => 'Relay A',
            'relay_2' => 'Relay B',
            'relay_3' => 'Relay C',
        ];

        $alias = $legacy[strtolower($value)] ?? null;

        return $alias ? $this->relayByName($alias) : null;
    }

    /**
     * Case- and spacing-insensitive, so "relay 4" in the sheet still matches
     * "Relay 4" in the table.
     */
    private function relayByName(string $name)
    {
        return \App\Models\Relay::whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->first();
    }

    /**
     * parseDate throws, which loses the row and column. This reports instead,
     * so a bad date fails validation like every other column.
     */
    private function tryDate($value): ?Carbon
    {
        try {
            return $this->parseDate($value);
        } catch (\Throwable $e) {
            return null;
        }
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
