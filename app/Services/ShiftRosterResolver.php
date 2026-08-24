<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftHistory;
use App\Models\EmployeeShiftOverride;
use App\Models\RelayShiftMapping;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves which shift an employee was rostered on for a given day, and how
 * long that shift runs.
 *
 * attendance_processeds.shift_id is mostly NULL, so a day's shift cannot be read
 * off the attendance row alone — the roster is the real source. Everything that
 * prices a day against its shift (the Attendance Register's OT column and
 * payroll overtime) has to resolve it the same way, or the same day yields
 * different overtime depending on which screen you look at.
 */
class ShiftRosterResolver
{
    /**
     * Fallback shift length for days where no shift resolves at all.
     */
    public const STANDARD_WORKING_HOURS = 8.0;

    /**
     * Longest span a single attendance day may cover, in hours.
     *
     * Rolling a check-out past midnight is unconditional, so a day shift whose
     * check-out was mistyped (09:00 to "06:00" instead of 16:00) would otherwise
     * become a silent 21-hour day and inflate that day's overtime. No real shift
     * here runs anywhere near this long, so anything above it is a bad row.
     */
    public const MAX_ATTENDANCE_SPAN_HOURS = 16.0;

    /**
     * The check-out belonging to a check-in, moved onto the next calendar day
     * when the shift ran past midnight.
     *
     * Both times arrive stamped against one date — Excel gives a night shift as
     * date 01-08, in 22:00, out 06:00 — so the raw check-out lands 16 hours
     * before its own check-in. Same rule scheduledHours() applies to a shift's
     * own clock: finishing at or before the start means the next day.
     */
    public static function resolveCheckOut(Carbon $checkIn, Carbon $checkOut): Carbon
    {
        $checkOut = $checkOut->copy();

        return $checkOut->lessThanOrEqualTo($checkIn)
            ? $checkOut->addDay()
            : $checkOut;
    }

    /**
     * A shift's length is its own clock: end_time - start_time.
     * minimum_working_hours is a separate payroll threshold and does not always
     * agree with the times, so it is deliberately not used here.
     */
    public function scheduledHours($shift): ?float
    {
        if (!$shift || !$shift->start_time || !$shift->end_time) {
            return null;
        }

        $start = Carbon::parse($shift->start_time);
        $end = Carbon::parse($shift->end_time);

        // A shift finishing at or before it starts runs past midnight.
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return round($start->diffInMinutes($end) / 60, 2);
    }

    /**
     * shift id => length in hours, for every shift that has usable times.
     *
     * @return array<int,float>
     */
    public function shiftLengths(): array
    {
        return Shift::all()
            ->mapWithKeys(function ($shift) {
                return [$shift->id => $this->scheduledHours($shift)];
            })
            ->filter()
            ->all();
    }

    /**
     * Preload every table Employee::getShiftIdForDate() consults, for one set of
     * employees over one date range, so per-day resolution stays in memory.
     *
     * @param  Collection<int,Employee>  $employees
     */
    public function preload($employees, Carbon $startDate, Carbon $endDate): array
    {
        $employees = $employees instanceof Collection ? $employees : collect($employees);

        $employeeIds = $employees->pluck('id');
        $relayIds = $employees->pluck('relay_id')->filter()->unique()->values();

        $from = $startDate->format('Y-m-d');
        $to = $endDate->format('Y-m-d');

        $overrides = EmployeeShiftOverride::whereIn('employee_id', $employeeIds)
            ->whereDate('effective_from', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $from);
            })
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->groupBy('employee_id');

        $relayMappings = RelayShiftMapping::whereIn('relay_id', $relayIds)
            ->whereDate('week_start_date', '<=', $to)
            ->whereDate('week_end_date', '>=', $from)
            ->get()
            ->groupBy('relay_id');

        // Only consulted for today, mirroring the model's own fallback.
        $latestRelayMappings = RelayShiftMapping::whereIn('relay_id', $relayIds)
            ->orderBy('week_start_date', 'desc')
            ->get()
            ->groupBy('relay_id')
            ->map(function ($group) {
                return $group->first();
            });

        $assignments = EmployeeShiftAssignment::whereIn('employee_id', $employeeIds)
            ->get()
            ->groupBy('employee_id');

        $histories = EmployeeShiftHistory::whereIn('employee_id', $employeeIds)
            ->get()
            ->groupBy('employee_id');

        return compact('overrides', 'relayMappings', 'latestRelayMappings', 'assignments', 'histories');
    }

    /**
     * In-memory twin of Employee::getShiftIdForDate(). Kept in step with that
     * method — if the precedence there changes, change it here too.
     */
    public function resolve($employee, string $dateStr, array $ctx)
    {
        $asDate = function ($value) {
            if (!$value) {
                return null;
            }
            return $value instanceof \DateTimeInterface
                ? Carbon::instance($value)->format('Y-m-d')
                : (string) $value;
        };

        // 1. An individual override outranks everything.
        $override = ($ctx['overrides'][$employee->id] ?? collect())
            ->first(function ($o) use ($dateStr, $asDate) {
                $start = $asDate($o->effective_from);
                $end = $asDate($o->effective_until);
                return $start && $start <= $dateStr && (is_null($end) || $end >= $dateStr);
            });
        if ($override) {
            return $override->shift_id;
        }

        // 2. Rotating relays get their shift from the week's mapping.
        if ($employee->relay_id && $employee->relay && $employee->relay->is_rotating) {
            $mapping = ($ctx['relayMappings'][$employee->relay_id] ?? collect())
                ->first(function ($m) use ($dateStr, $asDate) {
                    return $asDate($m->week_start_date) <= $dateStr
                        && $asDate($m->week_end_date) >= $dateStr;
                });
            if ($mapping) {
                return $mapping->shift_id;
            }

            if ($dateStr === now()->toDateString()) {
                $latest = $ctx['latestRelayMappings'][$employee->relay_id] ?? null;
                if ($latest) {
                    return $latest->shift_id;
                }
            }
        }

        // 3. Legacy assignments, for months predating the relay mappings.
        $assignments = $ctx['assignments'][$employee->id] ?? collect();

        $assignment = $assignments->first(function ($assign) use ($dateStr, $asDate) {
            $start = $asDate($assign->from_date) ?: $asDate($assign->created_at) ?: now()->toDateString();
            $end = $asDate($assign->to_date);
            return $start && $dateStr >= $start && (is_null($end) || $dateStr <= $end);
        });
        if ($assignment) {
            return $assignment->shift_id;
        }

        $history = ($ctx['histories'][$employee->id] ?? collect())
            ->sortBy(function ($h) use ($asDate) {
                return $asDate($h->change_date) . '|' . str_pad((string) $h->id, 12, '0', STR_PAD_LEFT);
            })
            ->values();

        $nextChange = $history->first(function ($h) use ($dateStr, $asDate) {
            return $asDate($h->change_date) > $dateStr;
        });
        if ($nextChange) {
            return $nextChange->old_shift_id ?: null;
        }

        $latestChange = $history->last(function ($h) use ($dateStr, $asDate) {
            return $asDate($h->change_date) <= $dateStr;
        });
        if ($latestChange) {
            return $latestChange->new_shift_id;
        }

        $firstAssignment = $assignments->sortBy('id')->first();
        if ($firstAssignment) {
            $start = $asDate($firstAssignment->from_date) ?: $asDate($firstAssignment->created_at) ?: now()->toDateString();
            if ($dateStr < $start) {
                return null;
            }
        }

        $latestAssignment = $assignments->sortByDesc('id')->first();
        return $latestAssignment ? $latestAssignment->shift_id : null;
    }
}
