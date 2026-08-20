<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of an uploaded Form B spreadsheet, parsed and validated.
 *
 * A row that failed validation is still stored — the user needs to see what was
 * rejected and why, so the failing cells can be marked in the preview.
 */
class WageRegisterUploadRow extends Model
{
    use HasFactory;

    /**
     * The sheet's column order: spreadsheet index => [field, printed column
     * number, type]. Drives parsing, validation and the error payload, so the
     * layout is described once rather than repeated in each of them.
     */
    public const COLUMNS = [
        0  => ['employee_code',      1,  'code'],
        1  => ['employee_name',      2,  'text'],
        2  => ['rate_of_wage',       3,  'amount'],
        3  => ['days_worked',        4,  'amount'],
        4  => ['overtime_hours',     5,  'amount'],
        5  => ['basic',              6,  'amount'],
        6  => ['special_basic',      7,  'amount'],
        7  => ['dearness_allowance', 8,  'amount'],
        8  => ['overtime_payment',   9,  'amount'],
        9  => ['hra',                10, 'amount'],
        10 => ['other_earnings',     11, 'amount'],
        11 => ['total_earnings',     12, 'amount'],
        12 => ['pf_deduction',       13, 'amount'],
        13 => ['esic_deduction',     14, 'amount'],
        14 => ['society_deduction',  15, 'amount'],
        15 => ['income_tax',         16, 'amount'],
        16 => ['insurance',          17, 'amount'],
        17 => ['other_deductions',   18, 'amount'],
        18 => ['recoveries',         19, 'amount'],
        19 => ['total_deductions',   20, 'amount'],
        20 => ['net_payment',        21, 'amount'],
        21 => ['employer_pf_share',  22, 'amount'],
        22 => ['payment_reference',  23, 'text'],
        23 => ['payment_date',       24, 'date'],
        24 => ['remarks',            25, 'text'],
    ];

    /** Printed column headings, for error messages the user will recognise. */
    public const COLUMN_LABELS = [
        'employee_code' => 'S. No. In Employee Register',
        'employee_name' => 'Name',
        'rate_of_wage' => 'Rate of Wage',
        'days_worked' => 'No. of days worked',
        'overtime_hours' => 'Overtime hours Worked',
        'basic' => 'Basic',
        'special_basic' => 'Special basic',
        'dearness_allowance' => 'Dearness Allowance',
        'overtime_payment' => 'Payments Overtime',
        'hra' => 'HRA',
        'other_earnings' => '*Others',
        'total_earnings' => 'Total',
        'pf_deduction' => 'PF',
        'esic_deduction' => 'ESIC',
        'society_deduction' => 'Society',
        'income_tax' => 'Income Tax',
        'insurance' => 'Insurance',
        'other_deductions' => 'Others',
        'recoveries' => 'Recoveries',
        'total_deductions' => 'Total',
        'net_payment' => 'Net Payment',
        'employer_pf_share' => 'Employer Share PF Welfare Fund',
        'payment_reference' => 'Receipt by Employee/Bank Transaction ID',
        'payment_date' => 'Date of Payment',
        'remarks' => 'Remarks',
    ];

    protected $fillable = [
        'upload_id', 'excel_row', 'employee_id', 'employee_code', 'employee_name',
        'rate_of_wage', 'days_worked', 'overtime_hours', 'basic', 'special_basic',
        'dearness_allowance', 'overtime_payment', 'hra', 'other_earnings', 'total_earnings',
        'pf_deduction', 'esic_deduction', 'society_deduction', 'income_tax', 'insurance',
        'other_deductions', 'recoveries', 'total_deductions', 'net_payment', 'employer_pf_share',
        'payment_reference', 'payment_date', 'remarks',
        'raw_data', 'errors', 'is_valid',
    ];

    protected $casts = [
        'excel_row' => 'integer',
        'is_valid' => 'boolean',
        'raw_data' => 'array',
        'errors' => 'array',
        'payment_date' => 'date:Y-m-d',
        'rate_of_wage' => 'float', 'days_worked' => 'float', 'overtime_hours' => 'float',
        'basic' => 'float', 'special_basic' => 'float', 'dearness_allowance' => 'float',
        'overtime_payment' => 'float', 'hra' => 'float', 'other_earnings' => 'float',
        'total_earnings' => 'float', 'pf_deduction' => 'float', 'esic_deduction' => 'float',
        'society_deduction' => 'float', 'income_tax' => 'float', 'insurance' => 'float',
        'other_deductions' => 'float', 'recoveries' => 'float', 'total_deductions' => 'float',
        'net_payment' => 'float', 'employer_pf_share' => 'float',
    ];

    public function upload()
    {
        return $this->belongsTo(WageRegisterUpload::class, 'upload_id');
    }

    /**
     * The shape the preview table renders from: the values, plus which cells
     * failed and the printed column numbers to mark.
     */
    public function toPreview(): array
    {
        $errors = $this->errors ?: [];
        $raw = $this->raw_data ?: [];
        $values = [];

        foreach (self::COLUMNS as [$field, , $type]) {
            $value = $this->{$field};

            // A cell that failed parsing was never stored — a bad figure is
            // refused rather than coerced, so the column is null. Showing that
            // null would leave the table blank beside an error message quoting
            // what was typed, and the user would have nothing to correct. So a
            // failing cell shows the text as it came off the sheet.
            if (isset($errors[$field]) && array_key_exists($field, $raw)) {
                $values[$field] = $raw[$field];
                continue;
            }

            // A date attribute comes back as a Carbon instance, which serialises
            // to a UTC timestamp and can land a day earlier than the date the
            // user typed. The sheet holds a plain calendar date, so return one.
            $values[$field] = ($type === 'date' && $value)
                ? $value->toDateString()
                : $value;
        }

        // A 1/0 for every field, in the same key order as `values`, so a cell can
        // be marked without checking whether a key exists in `errors`.
        $flags = [];

        foreach (self::COLUMNS as [$field, , ]) {
            $flags[$field] = isset($errors[$field]) ? 1 : 0;
        }

        return [
            'id' => $this->id,
            'excel_row' => $this->excel_row,
            'employee_id' => $this->employee_id,
            'employee_code' => $this->employee_code,
            'employee_name' => $this->employee_name,

            'is_valid' => $this->is_valid,
            'status' => $this->is_valid ? 'valid' : 'error',

            'values' => $values,

            // Every field, 1 when that cell is in error and 0 when it is not.
            'error_flags' => $flags,

            // Only the failing fields, with the reason — for the tooltip.
            'errors' => $errors,

            // The same cells as printed column numbers, for a table that is
            // laid out by Form B column rather than by field name.
            'error_columns' => self::columnNumbersFor(array_keys($errors)),
        ];
    }

    /**
     * Maps field names to the column numbers printed on Form B.
     */
    public static function columnNumbersFor(array $fields): array
    {
        $map = [];

        foreach (self::COLUMNS as [$field, $number, ]) {
            $map[$field] = $number;
        }

        return array_values(array_filter(array_map(
            fn($field) => $map[$field] ?? null,
            $fields
        )));
    }
}
