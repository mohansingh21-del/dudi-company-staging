<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One printed line of Form E, frozen. The employee's name and code are copied
 * in rather than joined, so the register still reads correctly if the employee
 * record is later renamed or removed.
 */
class LeaveRegisterReportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_id',
        'employee_id',
        'serial_no',
        'employee_code',
        'employee_name',
        'days_worked',
        'comp_rest_opening', 'comp_rest_added', 'comp_rest_not_allowed', 'comp_rest_availed', 'comp_rest_closing',
        'earned_opening', 'earned_added', 'earned_availed', 'earned_closing',
        'medical_opening', 'medical_added', 'medical_availed', 'medical_closing',
        'other_opening', 'other_added', 'other_availed', 'other_closing',
        'remarks',
    ];

    protected $casts = [
        'serial_no' => 'integer',
        'days_worked' => 'float',
        'comp_rest_opening' => 'float',
        'comp_rest_added' => 'float',
        'comp_rest_not_allowed' => 'float',
        'comp_rest_availed' => 'float',
        'comp_rest_closing' => 'float',
        'earned_opening' => 'float',
        'earned_added' => 'float',
        'earned_availed' => 'float',
        'earned_closing' => 'float',
        'medical_opening' => 'float',
        'medical_added' => 'float',
        'medical_availed' => 'float',
        'medical_closing' => 'float',
        'other_opening' => 'float',
        'other_added' => 'float',
        'other_availed' => 'float',
        'other_closing' => 'float',
    ];

    /** Maps a register_group to this table's column prefix. */
    const GROUP_PREFIX = [
        'compensatory_rest' => 'comp_rest',
        'earned' => 'earned',
        'medical' => 'medical',
        'other' => 'other',
    ];

    public function report()
    {
        return $this->belongsTo(LeaveRegisterReport::class, 'report_id');
    }

    /**
     * One printed line of Form E, flat, in the form's own column order.
     * Keys carry the printed column numbers in the comments.
     */
    public function toFormE(): array
    {
        return [
            'serial_no' => $this->serial_no,                       // col 1
            'employee_id' => $this->employee_id,
            'employee_code' => $this->employee_code,
            'name' => $this->employee_name,                        // col 2
            'days_worked' => (float) $this->days_worked,           // col 3

            'comp_rest_opening' => (float) $this->comp_rest_opening,        // col 4
            'comp_rest_added' => (float) $this->comp_rest_added,            // col 5
            'comp_rest_not_allowed' => (float) $this->comp_rest_not_allowed, // col 6
            'comp_rest_availed' => (float) $this->comp_rest_availed,        // col 7
            'comp_rest_closing' => (float) $this->comp_rest_closing,        // col 8

            'earned_opening' => (float) $this->earned_opening,     // col 9
            'earned_added' => (float) $this->earned_added,         // col 10
            'earned_availed' => (float) $this->earned_availed,     // col 11
            'earned_closing' => (float) $this->earned_closing,     // col 12

            'medical_opening' => (float) $this->medical_opening,   // col 13
            'medical_added' => (float) $this->medical_added,       // col 14
            'medical_availed' => (float) $this->medical_availed,   // col 15
            'medical_closing' => (float) $this->medical_closing,   // col 16

            'other_opening' => (float) $this->other_opening,       // col 17
            'other_added' => (float) $this->other_added,           // col 18
            'other_availed' => (float) $this->other_availed,       // col 19
            'other_closing' => (float) $this->other_closing,       // col 20

            'remarks' => $this->remarks,                           // col 25
        ];
    }

    /**
     * The same flat shape, built from a computed ledger entry instead of a
     * stored row, so the live preview and a saved report render identically.
     */
    public static function formEFromLedger(int $serialNo, $employee, array $entry): array
    {
        $row = [
            'serial_no' => $serialNo,
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'name' => trim($employee->name . ' ' . $employee->surname),
            'days_worked' => (float) ($entry['days_worked'] ?? 0),
        ];

        foreach (LeaveType::REGISTER_GROUPS as $group => $label) {
            $prefix = self::GROUP_PREFIX[$group];
            $figures = $entry['groups'][$group] ?? [];

            $row[$prefix . '_opening'] = (float) ($figures['opening_balance'] ?? 0);
            $row[$prefix . '_added'] = (float) ($figures['added'] ?? 0);

            if ($group === 'compensatory_rest') {
                $row['comp_rest_not_allowed'] = (float) ($figures['rest_not_allowed'] ?? 0);
            }

            $row[$prefix . '_availed'] = (float) ($figures['availed'] ?? 0);
            $row[$prefix . '_closing'] = (float) ($figures['closing_balance'] ?? 0);
        }

        $row['remarks'] = null;

        return $row;
    }
}
