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
}
