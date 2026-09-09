<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The Form E master: four fixed statutory blocks, one row each.
 *
 * Rows are seeded by migration and cannot be created or deleted through the
 * API — the register always prints the same four blocks. Only the annual quota
 * (allowed_days) and the active flag are editable.
 */
class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'leave_category', 'register_group', 'allowed_days', 'is_active'];

    protected $casts = [
        'allowed_days' => 'integer',
        'is_active' => 'boolean',
    ];

    /** The four blocks Form E prints, in register column order. */
    const REGISTER_GROUPS = [
        'compensatory_rest' => 'Compensatory Rest',
        'earned' => 'Earned Leave',
        'medical' => 'Medical Leave',
        'other' => 'Other Leave',
    ];

    /**
     * Paid/unpaid follows the block rather than being typed in: the three
     * statutory entitlements are paid, everything under Other is unpaid.
     */
    const PAID_GROUPS = ['compensatory_rest', 'earned', 'medical'];

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    public function scopeOnRegister($query)
    {
        return $query->whereNotNull('register_group');
    }

    public function getRegisterGroupLabelAttribute()
    {
        return self::REGISTER_GROUPS[$this->register_group] ?? null;
    }

    public static function categoryForGroup(string $group): string
    {
        return in_array($group, self::PAID_GROUPS, true) ? 'paid' : 'unpaid';
    }

    /**
     * Paid/unpaid for this row, taken from the block rather than the stored
     * column so the two cannot drift. Legacy rows carry no block, so those fall
     * back to whatever category they were saved with.
     */
    public function effectiveCategory(): string
    {
        return $this->register_group
            ? self::categoryForGroup($this->register_group)
            : (string) $this->leave_category;
    }

    /**
     * Is this block drawn from an entitlement that can run out?
     *
     * Only paid blocks are. Unpaid leave is not credited from a quota at all —
     * a worker can take any number of unpaid days — so allowed_days is not a
     * limit on it and a 0 there means nothing.
     */
    public function isMetered(): bool
    {
        return $this->effectiveCategory() !== 'unpaid';
    }

    /**
     * Can leave be applied against this block yet?
     *
     * A paid block is credited from allowed_days alone, so one the admin has
     * not given a quota to has nothing to give: the leave would be availed
     * against an entitlement of zero and the closing balance would simply floor
     * it away. Paid blocks ship at 0, so this is the normal state of one until
     * someone configures it.
     *
     * Unpaid leave is never gated — see isMetered().
     */
    public function canApply(): bool
    {
        return ! $this->isMetered() || (int) $this->allowed_days > 0;
    }

    /**
     * One wording for the refusal, shared by the apply form and the bulk sheet.
     */
    public function quotaMissingMessage(): string
    {
        return "No days are assigned for {$this->name} in the leave master. "
            . 'Set its allowed days before applying this leave.';
    }
}
