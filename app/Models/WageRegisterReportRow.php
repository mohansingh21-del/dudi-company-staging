<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One printed line of Form B, frozen. The employee's name and code are copied
 * in rather than joined, so the register still reads correctly if the employee
 * record is later renamed or removed.
 */
class WageRegisterReportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_id',
        'employee_id',
        'serial_no',
        'employee_code',
        'employee_name',
        'skill_category',
        'rate_of_wage',
        'days_worked',
        'overtime_hours',
        'basic',
        'special_basic',
        'dearness_allowance',
        'overtime_payment',
        'hra',
        'other_earnings',
        'total_earnings',
        'pf_deduction',
        'esic_deduction',
        'society_deduction',
        'income_tax',
        'insurance',
        'other_deduction',
        'mess_deduction',
        'penalty_deduction',
        'absence_deduction',
        'recoveries',
        'total_deductions',
        'net_payment',
        'employer_pf_share',
        'payment_reference',
        'payment_date',
        'remarks',
    ];

    protected $casts = [
        'serial_no' => 'integer',
        'rate_of_wage' => 'float',
        'days_worked' => 'float',
        'overtime_hours' => 'float',
        'basic' => 'float',
        'special_basic' => 'float',
        'dearness_allowance' => 'float',
        'overtime_payment' => 'float',
        'hra' => 'float',
        'other_earnings' => 'float',
        'total_earnings' => 'float',
        'pf_deduction' => 'float',
        'esic_deduction' => 'float',
        'society_deduction' => 'float',
        'income_tax' => 'float',
        'insurance' => 'float',
        'other_deduction' => 'float',
        'mess_deduction' => 'float',
        'penalty_deduction' => 'float',
        'absence_deduction' => 'float',
        'recoveries' => 'float',
        'total_deductions' => 'float',
        'net_payment' => 'float',
        'employer_pf_share' => 'float',
        'payment_date' => 'date:Y-m-d',
    ];

    public function report()
    {
        return $this->belongsTo(WageRegisterReport::class, 'report_id');
    }

    /**
     * One printed line of Form B, flat, in the form's own column order. Keys
     * carry the printed column numbers in the comments.
     *
     * Column 18 "Others" is the sum of the three stored deduction components;
     * the breakdown rides along so a disputed figure can be traced.
     */
    public function toFormB(): array
    {
        return [
            'serial_no' => $this->serial_no,                             // col 1
            'employee_id' => $this->employee_id,
            'employee_code' => $this->employee_code,
            'name' => $this->employee_name,                              // col 2
            'skill_category' => $this->skill_category,

            'rate_of_wage' => self::nullableAmount($this->rate_of_wage),  // col 3
            'days_worked' => (float) $this->days_worked,                 // col 4
            'overtime_hours' => (float) $this->overtime_hours,           // col 5
            'basic' => (float) $this->basic,                             // col 6
            'special_basic' => self::nullableAmount($this->special_basic),        // col 7
            'dearness_allowance' => self::nullableAmount($this->dearness_allowance), // col 8
            'overtime_payment' => (float) $this->overtime_payment,       // col 9
            'hra' => self::nullableAmount($this->hra),                   // col 10
            'other_earnings' => self::nullableAmount($this->other_earnings),      // col 11
            'total_earnings' => (float) $this->total_earnings,           // col 12

            'pf_deduction' => (float) $this->pf_deduction,               // col 13
            'esic_deduction' => self::nullableAmount($this->esic_deduction),      // col 14
            'society_deduction' => self::nullableAmount($this->society_deduction), // col 15
            'income_tax' => self::nullableAmount($this->income_tax),     // col 16
            'insurance' => self::nullableAmount($this->insurance),       // col 17
            'other_deductions' => $this->otherDeductionsTotal(),         // col 18
            'other_deductions_breakup' => [
                'other_deduction' => (float) $this->other_deduction,
                'mess_deduction' => (float) $this->mess_deduction,
            ],
            'recoveries' => $this->recoveriesTotal(),                    // col 19
            'recoveries_breakup' => [
                'penalty_deduction' => (float) $this->penalty_deduction,
                'other_recoveries' => (float) $this->recoveries,
            ],
            'total_deductions' => (float) $this->total_deductions,       // col 20

            'net_payment' => (float) $this->net_payment,                 // col 21

            // Not a Form B column and never printed. Wages for days not worked,
            // taken off the net directly, which is why column 21 does not equal
            // column 12 minus column 20 for anyone short of a full month.
            'absence_deduction' => (float) $this->absence_deduction,

            // Not a Form B column. Non-zero means columns 13-19 exceeded column
            // 12 and the excess was written off, so col 21 reads 0 rather than
            // 12 minus 20. Lets the UI flag a row that needs looking at.
            'unrecovered_deduction' => $this->unrecoveredDeduction(),
            'employer_pf_share' => self::nullableAmount($this->employer_pf_share), // col 22
            'payment_reference' => $this->payment_reference,             // col 23
            'payment_date' => optional($this->payment_date)->toDateString(), // col 24
            'remarks' => $this->remarks,                                 // col 25
        ];
    }

    /**
     * How much of columns 13-19 could not be taken because the employee did not
     * earn enough to cover it. Net payment is floored at 0, so without this the
     * shortfall would vanish from the register entirely.
     */
    public function unrecoveredDeduction(): float
    {
        $charged = (float) $this->total_deductions + (float) $this->absence_deduction;

        return round(max(0, $charged - (float) $this->total_earnings), 2);
    }

    /**
     * Column 18 as printed. Penalties are not among them — they belong in
     * column 19, Recoveries.
     *
     * Absence is not here either. It is pay never earned rather than money the
     * employer held back, it has no column on Form B, and it comes off the net
     * directly — see net_payment.
     */
    public function otherDeductionsTotal(): float
    {
        return round(
            (float) $this->other_deduction
            + (float) $this->mess_deduction,
            2
        );
    }

    /**
     * Column 19 as printed: money recovered from the employee, which for this
     * establishment means penalties. The stored `recoveries` field is added on
     * so any other kind of recovery can join it later without changing the sum.
     */
    public function recoveriesTotal(): float
    {
        return round(
            (float) $this->penalty_deduction
            + (float) $this->recoveries,
            2
        );
    }

    /**
     * Keeps a genuinely unsourced column blank on the form rather than showing
     * a 0 the employer never actually paid or withheld.
     */
    protected static function nullableAmount($value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
