<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The minimum wage rate master behind Form B — one dated revision per skill
 * category, keyed by the employee's skill_category enum.
 *
 * The rate is not stored on the employee: payroll reads it at assignment time
 * and copies the resulting basic salary onto employee_payrolls, so revising a
 * rate here never silently rewrites pay that has already been assigned.
 */
class EmployeeWage extends Model
{
    use HasFactory;

    /** Mirrors the employees.skill_category enum. */
    public const SKILL_CATEGORIES = [
        'highly_skilled',
        'skilled',
        'semi_skilled',
        'unskilled',
    ];

    /** Employee's EPF contribution share, as a fraction of basic salary. */
    public const PF_RATE = 0.12;

    protected $fillable = [
        'skill_category',
        'minimum_basic',
        'dearness_allowance',
        'overtime_rate',
        'effective_from',
        'is_active',
    ];

    protected $casts = [
        'minimum_basic' => 'decimal:2',
        'dearness_allowance' => 'decimal:2',
        'overtime_rate' => 'decimal:2',
        'effective_from' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    /**
     * The figure payroll assigns as basic salary: minimum basic plus DA.
     */
    public function getBasicSalaryAttribute()
    {
        return round((float) $this->minimum_basic + (float) $this->dearness_allowance, 2);
    }

    /**
     * The employee's statutory PF share on that basic — 12% of it, the figure
     * the payroll form prefills pf_amount with. Payroll still stores its own
     * copy, so revising a rate here never rewrites PF already assigned.
     */
    public function getPfAmountAttribute()
    {
        return round($this->basic_salary * self::PF_RATE, 2);
    }

    public function getSkillCategoryLabelAttribute()
    {
        if (!$this->skill_category) {
            return null;
        }

        return ucwords(str_replace('_', '-', $this->skill_category), '-');
    }

    /**
     * The monthly pay a payroll month is priced at.
     *
     * A payroll month must be priced at the rate that was in force that month,
     * not at whatever the master says today — otherwise generating a month that
     * is still pending would pay it at a later revision's rate. Callers pass
     * the effectiveSet() map for the month being generated and the figure
     * frozen on employee_payrolls; the higher of the two wins, so a
     * revision lifts everyone on the statutory minimum while an above-minimum
     * salary keeps its own figure. An employee with no skill category, or a
     * category with no rate configured, keeps the stored figure.
     */
    public static function monthlyPay(array $rates, ?string $skillCategory, $storedBasic): float
    {
        $stored = (float) $storedBasic;
        $wage = $skillCategory ? ($rates[$skillCategory] ?? null) : null;

        return $wage ? max((float) $wage->basic_salary, $stored) : $stored;
    }

    /**
     * The revision in force on a date — the latest one that had already taken
     * effect by then.
     */
    public function scopeEffectiveOn($query, $date = null)
    {
        return $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $date ?: now()->toDateString());
    }

    /**
     * The rate applying to a skill category on a date, or null when nothing has
     * been set up for it yet.
     */
    public static function effectiveFor(?string $skillCategory, $date = null): ?self
    {
        if (!$skillCategory) {
            return null;
        }

        return static::query()
            ->where('skill_category', $skillCategory)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The four rates in force on a date, keyed by skill category — the shape
     * the Form B header is printed from. Categories with no rate yet are null.
     */
    public static function effectiveSet($date = null): array
    {
        $rows = static::query()
            ->effectiveOn($date)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            // Later rows overwrite earlier ones, leaving the latest per category.
            ->keyBy('skill_category');

        $set = [];

        foreach (self::SKILL_CATEGORIES as $category) {
            $set[$category] = $rows->get($category);
        }

        return $set;
    }
}
