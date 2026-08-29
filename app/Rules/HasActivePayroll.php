<?php

namespace App\Rules;

use App\Models\Employee;
use Illuminate\Contracts\Validation\Rule;

/**
 * An employee must have an active payroll (salary structure) before a
 * penalty can be raised against them.
 *
 * Without one the gross is 0, so the 25% monthly recovery cap is also 0
 * and nothing can ever be deducted — the penalty would sit in
 * recovery_carried_forward forever while the payroll screen shows 0.
 *
 * Pass 'employee_code' when validating a column that holds the code
 * instead of the id (the Excel import does).
 */
class HasActivePayroll implements Rule
{
    private string $by;

    private string $message = 'The selected employee has no active payroll assigned.';

    public function __construct(string $by = 'id')
    {
        $this->by = $by;
    }

    public function passes($attribute, $value)
    {
        $employee = Employee::with('activePayroll')
            ->where($this->by, is_string($value) ? trim($value) : $value)
            ->first();

        // A missing employee is the exists: rule's business, not ours.
        if (!$employee) {
            return true;
        }

        if ($employee->activePayroll) {
            return true;
        }

        $this->message = "No active payroll is assigned to employee "
            . "{$employee->employee_code}, so a penalty cannot be raised "
            . "against them.";

        return false;
    }

    public function message()
    {
        return $this->message;
    }
}
