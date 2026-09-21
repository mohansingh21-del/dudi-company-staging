<?php

namespace App\Rules;

use App\Models\Shift;
use Illuminate\Contracts\Validation\Rule;

/**
 * The selected shift must be active. An inactive shift is kept only so old
 * records still resolve, so nothing new may be put on it.
 *
 * Pass the record's current shift id when editing, so a record that already
 * sits on a since-deactivated shift can still be saved without changing it.
 *
 * Pass 'shift_name' when validating a column that holds the name instead of
 * the id (the Excel imports do).
 */
class ActiveShift implements Rule
{
    private $allowedShiftId;

    private string $by;

    private string $name = '';

    public function __construct($allowedShiftId = null, string $by = 'id')
    {
        $this->allowedShiftId = $allowedShiftId;
        $this->by = $by;
    }

    public function passes($attribute, $value)
    {
        if ($value === null || $value === '') {
            return true;
        }

        $shift = Shift::where($this->by, is_string($value) ? trim($value) : $value)->first();

        // A missing shift is the exists: rule's business, not ours.
        if (!$shift || $shift->is_active) {
            return true;
        }

        if ($this->allowedShiftId !== null && (int) $shift->id === (int) $this->allowedShiftId) {
            return true;
        }

        $this->name = $shift->shift_name;

        return false;
    }

    public function message()
    {
        return "The selected shift '{$this->name}' is inactive and cannot be used. Choose an active shift.";
    }

    /**
     * Same check outside the validator: the error message when the shift is
     * inactive, or null when it may be used.
     */
    public static function check($shiftId, $allowedShiftId = null): ?string
    {
        $rule = new static($allowedShiftId);

        return $rule->passes('shift_id', $shiftId) ? null : $rule->message();
    }
}
