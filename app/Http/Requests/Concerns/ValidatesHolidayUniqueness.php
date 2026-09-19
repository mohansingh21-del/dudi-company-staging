<?php

namespace App\Http\Requests\Concerns;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

/**
 * The one definition of a valid holiday, shared by the store and update
 * requests so the two cannot drift apart.
 *
 * Duplicate prevention is deliberately in two layers:
 *
 *   - here, so the admin gets a readable message on the field instead of a
 *     driver exception, and
 *   - a DB unique index over active (site_id, holiday_date), so a duplicate
 *     cannot get in through an import, a seeder, or two admins saving the same
 *     form at the same moment - a gap validation alone cannot close.
 */
trait ValidatesHolidayUniqueness
{
    /**
     * Put the input in the shape the duplicate check needs before it runs.
     *
     * The unique rule compares the submitted value against a DATE column, so
     * "15-08-2026" would be compared as a string and match nothing - the check
     * would pass and the duplicate would be written. Normalising to Y-m-d here
     * means one canonical form reaches both the rule and the database.
     *
     * An empty site_id becomes NULL rather than "", so a general holiday is
     * stored and matched as NULL consistently.
     */
    protected function normaliseHolidayInput(): void
    {
        $merge = [];

        $date = $this->input('holiday_date');

        if (is_string($date) && trim($date) !== '') {
            try {
                $merge['holiday_date'] = Carbon::parse($date)->format('Y-m-d');
            } catch (\Throwable $e) {
                // Unparseable: leave it alone so the `date` rule reports it.
            }
        }

        if ($this->exists('site_id') && ($this->input('site_id') === '' || $this->input('site_id') === 'null')) {
            $merge['site_id'] = null;
        }

        if ($merge) {
            $this->merge($merge);
        }
    }

    /**
     * The date already stored on the row being edited, or null on create.
     *
     * Overridden by the update request. It is what lets a holiday that has
     * already passed keep its date while its name or type is corrected -
     * without it, every edit of a historical row would be rejected.
     */
    protected function existingHolidayDate(): ?string
    {
        return null;
    }

    /**
     * @param  int|null  $ignoreId  the row being updated, excluded from the
     *                              duplicate check so saving it unchanged is
     *                              not reported as a clash with itself
     */
    protected function holidayRules(?int $ignoreId = null): array
    {
        $siteId = $this->input('site_id');

        $unique = Rule::unique('holidays', 'holiday_date')
            ->where(function ($query) use ($siteId) {
                $query->where('is_active', 1);

                // site_id is nullable and means "every site". A NULL has to be
                // matched with whereNull, not `= null`, or the rule silently
                // matches nothing and lets every general holiday through.
                if ($siteId === null || $siteId === '') {
                    $query->whereNull('site_id');
                } else {
                    $query->where('site_id', $siteId);
                }
            });

        if ($ignoreId) {
            $unique = $unique->ignore($ignoreId, 'id');
        }

        return [
            'holiday_name' => [
                'required',
                'string',
                'min:' . Holiday::MIN_NAME_LENGTH,
                'max:255',
                // At least one letter, so "1", "..." and "123" cannot pass the
                // length check on punctuation alone.
                'regex:/\pL/u',
            ],
            'holiday_date' => $this->holidayDateRules($unique),
            'site_id' => [
                'nullable',
                'integer',
                'exists:sites,id',
            ],
            'holiday_type' => [
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }

    /**
     * A holiday is an instruction for days still to be worked, so it cannot be
     * declared after the fact: attendance for a past date has already been
     * marked and, once the month is closed, paid on the rules that applied
     * then. Back-dating one silently changes what those days were worth.
     *
     * The rule is skipped when the submitted date is the one already on the
     * row, so an existing past holiday can still be renamed or re-typed - only
     * moving a date, or creating one, has to land today or later.
     */
    protected function holidayDateRules($unique): array
    {
        $rules = ['required', 'date'];

        $existing = $this->existingHolidayDate();
        $submitted = $this->input('holiday_date');

        if ($existing === null || $submitted !== $existing) {
            $rules[] = 'after_or_equal:today';
        }

        $rules[] = $unique;

        return $rules;
    }

    protected function holidayMessages(): array
    {
        return [
            'holiday_date.unique' => 'A holiday already exists for this site on this date.',
            'holiday_date.required' => 'Holiday date is required.',
            'holiday_date.date' => 'Holiday date must be a valid date.',
            'holiday_date.after_or_equal' => 'Holiday date cannot be in the past.',
            'holiday_name.required' => 'Holiday name is required.',
            'holiday_name.min' => 'Holiday name must be at least ' . Holiday::MIN_NAME_LENGTH . ' characters long.',
            'holiday_name.regex' => 'Holiday name must contain at least one letter.',
            'site_id.exists' => 'Selected site does not exist.',
            'site_id.integer' => 'Holiday site is required.',
        ];
    }

    /**
     * The clash Rule::unique cannot see: a site-specific holiday on a date
     * that is already an establishment-wide holiday. The site row would be
     * redundant - the general one already gives that site the day off - and
     * before the calculations counted distinct dates it paid the day twice.
     *
     * Only checked in this direction. Adding a general holiday on a date some
     * site already claims is allowed: blocking a company-wide holiday because
     * one site had already entered it locally would be the worse failure, and
     * the duplicate date is now harmless to the calculations either way.
     */
    protected function validateAgainstGeneralHoliday($validator, ?int $ignoreId = null): void
    {
        $siteId = $this->input('site_id');
        $date = $this->input('holiday_date');

        if (! $date || $siteId === null || $siteId === '') {
            return;
        }

        $query = Holiday::query()
            ->active()
            ->whereNull('site_id')
            ->whereDate('holiday_date', $date);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            $validator->errors()->add(
                'holiday_date',
                'This date is already a general holiday for all sites, so a separate entry for this site is not required.'
            );
        }
    }
}
