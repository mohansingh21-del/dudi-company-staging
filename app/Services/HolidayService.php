<?php

namespace App\Services;

use App\Models\AttendanceProcessed;
use App\Models\Holiday;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "how many holidays does this employee get paid
 * for in this month". Nothing here is stored.
 *
 * Two rules the callers kept getting wrong on their own:
 *
 *   1. A holiday is a DATE, not a row. The holidays table had no unique
 *      constraint for most of its life, so one calendar day can carry two or
 *      three rows (a general one plus a site one, or plain duplicates). Every
 *      caller used COUNT(*) and paid a day per row. Everything here works off
 *      a distinct set of Y-m-d strings instead.
 *
 *   2. A day is credited once. A holiday that lands on a day attendance
 *      already pays for - present, half day, or a weekly off marked rest_day -
 *      or on an approved paid leave, was adding a second payable day for the
 *      same date. Holidays are netted against those dates here, the same way
 *      LeaveBalanceService::monthlyLeaveDays() nets leave against attendance.
 *
 * Callers that only need to know "is this date a holiday" (calendar strips,
 * per-day status) can use dateMapForSite(); they are already date-keyed and
 * need no netting.
 */
class HolidayService
{
    /**
     * Distinct holiday dates applying to each of the given sites in a month.
     *
     * A general holiday (site_id NULL) applies to every site, so it is merged
     * into each site's set rather than counted alongside it - that merge is
     * what stops 15-Aug being paid twice when it exists both as a general row
     * and as a site row.
     *
     * @param  array  $siteIds  site ids to resolve; null entries are ignored
     * @return array  [site_id => ['Y-m-d' => true, ...]], plus key 0 for the
     *                general-only set used by employees with no site
     */
    public static function datesForSites(array $siteIds, int $month, int $year): array
    {
        $siteIds = array_values(array_unique(array_filter($siteIds, function ($id) {
            return $id !== null && $id !== '';
        })));

        $rows = Holiday::query()
            ->whereMonth('holiday_date', $month)
            ->whereYear('holiday_date', $year)
            ->where('is_active', true)
            ->where(function ($q) use ($siteIds) {
                $q->whereNull('site_id');

                if (! empty($siteIds)) {
                    $q->orWhereIn('site_id', $siteIds);
                }
            })
            ->get(['site_id', 'holiday_date']);

        $general = [];
        $bySite = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->holiday_date)->format('Y-m-d');

            if ($row->site_id === null) {
                $general[$date] = true;
            } else {
                $bySite[$row->site_id][$date] = true;
            }
        }

        // Key 0 carries the general set on its own, for employees with no site.
        $result = [0 => $general];

        foreach ($siteIds as $siteId) {
            $result[$siteId] = $general + ($bySite[$siteId] ?? []);
        }

        return $result;
    }

    /**
     * Distinct holiday dates for one site, general holidays included.
     *
     * @return array  ['Y-m-d' => true, ...]
     */
    public static function dateMapForSite($siteId, int $month, int $year): array
    {
        $sets = static::datesForSites($siteId === null ? [] : [$siteId], $month, $year);

        return $sets[$siteId === null ? 0 : $siteId] ?? [];
    }

    /**
     * Paid holiday days per employee for a month, netted against every other
     * day the month already pays for.
     *
     * @param  array  $employeeIds
     * @return array  [employee_id => int]
     */
    public static function monthlyHolidayDays(array $employeeIds, int $month, int $year): array
    {
        $employeeIds = array_values(array_unique($employeeIds));

        if (empty($employeeIds)) {
            return [];
        }

        $sites = DB::table('employees')
            ->whereIn('id', $employeeIds)
            ->pluck('site_id', 'id');

        $siteSets = static::datesForSites($sites->all(), $month, $year);

        $credited = static::daysAlreadyCredited($employeeIds, $month, $year);

        $summary = [];

        foreach ($employeeIds as $employeeId) {
            $siteId = $sites[$employeeId] ?? null;
            $dates = $siteSets[$siteId === null ? 0 : $siteId] ?? [];

            if (empty($dates)) {
                $summary[$employeeId] = 0;
                continue;
            }

            $taken = $credited[$employeeId] ?? [];

            $summary[$employeeId] = count(array_diff_key($dates, $taken));
        }

        return $summary;
    }

    /**
     * Paid holiday days for a single employee. Convenience wrapper for the
     * per-employee endpoints that resolve one row at a time.
     */
    public static function employeeHolidayDays(int $employeeId, int $month, int $year): int
    {
        $summary = static::monthlyHolidayDays([$employeeId], $month, $year);

        return $summary[$employeeId] ?? 0;
    }

    /**
     * Dates a month already pays for, per employee, so a holiday cannot pay
     * them a second time.
     *
     * Covered: attendance marked present / half_day / rest_day, and approved
     * leave whose register group resolves to a paid category. Unpaid leave and
     * absence are deliberately not here - those days are unpaid, so a holiday
     * falling on them is the only thing paying, and netting it out would cost
     * the employee a day.
     *
     * @return array  [employee_id => ['Y-m-d' => true, ...]]
     */
    protected static function daysAlreadyCredited(array $employeeIds, int $month, int $year): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $start = $monthStart->format('Y-m-d');
        $end = $monthEnd->format('Y-m-d');

        $credited = [];

        $attendanceRows = DB::table((new AttendanceProcessed)->getTable())
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('attendance_status', ['present', 'half_day', 'rest_day'])
            ->whereBetween('date', [$start, $end])
            ->get(['employee_id', 'date']);

        foreach ($attendanceRows as $row) {
            $credited[$row->employee_id][Carbon::parse($row->date)->format('Y-m-d')] = true;
        }

        $leaves = DB::table('leaves')
            ->join('leave_types', 'leaves.leave_type_id', '=', 'leave_types.id')
            ->where('leaves.status', 'approved')
            ->whereIn('leaves.employee_id', $employeeIds)
            ->whereDate('leaves.from_date', '<=', $end)
            ->whereDate('leaves.to_date', '>=', $start)
            ->get([
                'leaves.employee_id',
                'leaves.from_date',
                'leaves.to_date',
                'leave_types.register_group',
                'leave_types.leave_category',
            ]);

        foreach ($leaves as $leave) {
            $category = $leave->register_group
                ? LeaveType::categoryForGroup($leave->register_group)
                : ((string) $leave->leave_category === 'paid' ? 'paid' : 'unpaid');

            if ($category !== 'paid') {
                continue;
            }

            $from = Carbon::parse($leave->from_date)->startOfDay()->max($monthStart);
            $to = Carbon::parse($leave->to_date)->startOfDay()->min($monthEnd);

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $credited[$leave->employee_id][$day->format('Y-m-d')] = true;
            }
        }

        return $credited;
    }
}
