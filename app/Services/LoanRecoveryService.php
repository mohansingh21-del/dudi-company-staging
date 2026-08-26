<?php

namespace App\Services;

use App\Models\Penalty;
use App\Models\LoanRecoveryInstallment;
use App\Models\Payroll;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spreads employee recoveries across payroll months.
 *
 * An employee may not lose more than MAX_PERCENTAGE of one month's
 * gross to recoveries. Anything above that carries into the following
 * months until the balance clears.
 *
 * The cap is a single budget per employee per month, shared by every
 * recovery they carry — not a per-recovery allowance. Three recoveries
 * on one employee still come to MAX_PERCENTAGE in total.
 *
 * All amounts come from the immutable calculation_* snapshot, never
 * from the editable register columns, so correcting a typo in the
 * register cannot retroactively change money already deducted.
 */
class LoanRecoveryService
{
    /**
     * Maximum percentage of gross salary recoverable in one month.
     */
    public const MAX_PERCENTAGE = 25;

    /**
     * Installment states that represent money counted against a balance.
     * 'skipped' rows are audit trail only and do not reduce the balance.
     */
    private const COUNTED_STATUSES = ['deducted', 'pending'];

    /**
     * The maximum recoverable from one month's gross.
     */
    public function maximumMonthlyDeduction(float $grossSalary): float
    {
        return round($grossSalary * (self::MAX_PERCENTAGE / 100), 2);
    }

    /**
     * Every recovery of any type that has begun on or before this
     * payroll period, oldest first.
     *
     * Recoveries with no explicit start month fall back to the month
     * the penalty itself was raised, so a fine cannot be recovered in
     * a payroll month that precedes it.
     */
    public function getActiveRecoveries(
        int $employeeId,
        int $month,
        int $year
    ): Collection {
        return Penalty::where('employee_id', $employeeId)
            ->where('calculation_amount', '>', 0)
            ->whereRaw(
                '(COALESCE(calculation_first_year, year) < ?
                  OR (COALESCE(calculation_first_year, year) = ?
                      AND COALESCE(calculation_first_month, month) <= ?))',
                [$year, $year, $month]
            )
            ->orderByRaw('COALESCE(calculation_date, penalty_date) ASC')
            ->orderBy('id')
            ->get();
    }

    /**
     * Total already recovered against one penalty before the given
     * payroll period.
     */
    public function recoveredBefore(
        int $penaltyId,
        int $month,
        int $year
    ): float {
        return (float) LoanRecoveryInstallment::where('penalty_id', $penaltyId)
            ->whereIn('status', self::COUNTED_STATUSES)
            ->where(function ($query) use ($month, $year) {
                $query->where('year', '<', $year)
                    ->orWhere(function ($q) use ($month, $year) {
                        $q->where('year', $year)->where('month', '<', $month);
                    });
            })
            ->sum('installment_amount');
    }

    /**
     * Recovered-to-date for many penalties in one query.
     *
     * The per-penalty version issues a query each; the payroll listing
     * calls this once per employee, so the single-row version turns
     * into hundreds of queries on a real roster.
     *
     * Returns [penalty_id => amount], missing keys meaning nothing
     * recovered yet.
     */
    public function recoveredBeforeMany(
        array $penaltyIds,
        int $month,
        int $year
    ): array {
        if (empty($penaltyIds)) {
            return [];
        }

        return LoanRecoveryInstallment::whereIn('penalty_id', $penaltyIds)
            ->whereIn('status', self::COUNTED_STATUSES)
            ->where(function ($query) use ($month, $year) {
                $query->where('year', '<', $year)
                    ->orWhere(function ($q) use ($month, $year) {
                        $q->where('year', $year)->where('month', '<', $month);
                    });
            })
            ->groupBy('penalty_id')
            ->selectRaw('penalty_id, SUM(installment_amount) AS recovered')
            ->pluck('recovered', 'penalty_id')
            ->map(fn($v) => (float) $v)
            ->all();
    }

    /**
     * Work out what each recovery would take this month, without
     * writing anything. Safe to call from a GET.
     *
     * Returns:
     *   total         - deduct this much from the payroll
     *   budget        - the MAX_PERCENTAGE ceiling for the month
     *   carried       - balance still outstanding after this month
     *   lines         - per-recovery breakdown
     */
    public function planEmployeeRecoveries(
        int $employeeId,
        float $grossSalary,
        int $month,
        int $year
    ): array {
        $budget = $this->maximumMonthlyDeduction($grossSalary);
        $remainingBudget = $budget;

        $lines = [];
        $total = 0.0;
        $carried = 0.0;

        $recoveries = $this->getActiveRecoveries($employeeId, $month, $year);

        $recovered = $this->recoveredBeforeMany(
            $recoveries->pluck('id')->all(),
            $month,
            $year
        );

        foreach ($recoveries as $recovery) {

            $opening = round(
                (float) $recovery->calculation_amount
                    - ($recovered[$recovery->id] ?? 0.0),
                2
            );

            // Already settled in earlier months.
            if ($opening <= 0) {
                continue;
            }

            $installment = round(min($opening, $remainingBudget), 2);
            $remainingBudget = round($remainingBudget - $installment, 2);

            $closing = round($opening - $installment, 2);

            $total = round($total + $installment, 2);
            $carried = round($carried + $closing, 2);

            $lines[] = [
                'penalty_id' => $recovery->id,
                // Same precedence the ordering above uses.
                'date' => optional(
                    $recovery->calculation_date ?? $recovery->penalty_date
                )->toDateString(),
                'recovery_type' => $recovery->calculation_recovery_type
                    ?? $recovery->recovery_type,
                'particulars' => $recovery->calculation_particulars
                    ?? $recovery->particulars,
                'reason' => $recovery->reason,
                'total_amount' => (float) $recovery->calculation_amount,
                'opening_balance' => $opening,
                'installment_amount' => $installment,
                'closing_balance' => $closing,
                'fully_recovered' => $closing <= 0,
            ];
        }

        return [
            'total' => $total,
            'budget' => $budget,
            'carried' => $carried,
            'lines' => $lines,
        ];
    }

    /**
     * Same calculation as planEmployeeRecoveries(), but persists an
     * installment row per recovery.
     *
     * Once the month's payroll is paid the installments are frozen —
     * regenerating payroll must not rewrite money already handed over.
     */
    public function applyEmployeeRecoveries(
        int $employeeId,
        float $grossSalary,
        int $month,
        int $year,
        ?int $payrollId = null
    ): array {
        if ($this->isLocked($employeeId, $month, $year)) {
            return $this->frozenTotals($employeeId, $month, $year, $grossSalary);
        }

        return DB::transaction(function () use (
            $employeeId,
            $grossSalary,
            $month,
            $year,
            $payrollId
        ) {
            /*
             * Drop this month's rows first so a regenerate recalculates
             * against the current gross rather than stacking a second
             * installment onto the same period.
             */
            LoanRecoveryInstallment::where('employee_id', $employeeId)
                ->where('month', $month)
                ->where('year', $year)
                ->delete();

            $plan = $this->planEmployeeRecoveries(
                $employeeId,
                $grossSalary,
                $month,
                $year
            );

            $sequence = [];

            foreach ($plan['lines'] as $line) {
                if ($line['installment_amount'] <= 0) {
                    continue;
                }

                $penaltyId = $line['penalty_id'];

                $sequence[$penaltyId] ??= LoanRecoveryInstallment::where(
                    'penalty_id',
                    $penaltyId
                )->max('installment_number') ?? 0;

                LoanRecoveryInstallment::create([
                    'penalty_id' => $penaltyId,
                    'employee_id' => $employeeId,
                    'month' => $month,
                    'year' => $year,
                    'installment_number' => ++$sequence[$penaltyId],
                    'opening_balance' => $line['opening_balance'],
                    'salary_basis' => $grossSalary,
                    'maximum_allowed_amount' => $plan['budget'],
                    'installment_amount' => $line['installment_amount'],
                    'closing_balance' => $line['closing_balance'],
                    'status' => $payrollId ? 'deducted' : 'pending',
                    'payroll_id' => $payrollId,
                    'deduction_date' => $payrollId ? now()->toDateString() : null,
                ]);
            }

            return $plan;
        });
    }

    /**
     * Project the recovery schedule for an amount that has not been
     * saved yet, so the form can show it before the user commits.
     *
     * The employee's existing recoveries are simulated forward
     * alongside the proposed one, because they share the same monthly
     * budget — a new advance for someone already repaying a loan
     * recovers slower, and the preview has to say so.
     *
     * Future months are projected at today's gross. Overtime moves the
     * cap, so treat the tail of a long schedule as an estimate.
     */
    public function projectSchedule(
        int $employeeId,
        float $grossSalary,
        float $amount,
        string $penaltyDate,
        ?int $firstMonth = null,
        ?int $firstYear = null,
        int $maxMonths = 120
    ): array {
        $budget = $this->maximumMonthlyDeduction($grossSalary);
        $start = \Carbon\Carbon::create(
            $firstYear ?: (int) date('Y', strtotime($penaltyDate)),
            $firstMonth ?: (int) date('n', strtotime($penaltyDate)),
            1
        );

        /*
         * A salary too small to recover anything would loop forever.
         */
        if ($budget <= 0 || $amount <= 0) {
            return [
                'salary_basis' => $grossSalary,
                'monthly_limit' => $budget,
                'amount' => round($amount, 2),
                'installment_amount' => null,
                'number_of_installments' => 0,
                'first_month' => null,
                'first_year' => null,
                'last_month' => null,
                'last_year' => null,
                'date_of_complete_recovery' => null,
                'fully_recoverable' => false,
                'schedule' => [],
                'competing_recoveries' => [],
            ];
        }

        // Existing obligations, each with its own start gate and balance.
        $existing = [];

        $rows = Penalty::where('employee_id', $employeeId)
            ->where('calculation_amount', '>', 0)
            ->get([
                'id', 'year', 'month', 'penalty_date',
                'calculation_amount', 'calculation_date',
                'calculation_particulars', 'calculation_recovery_type',
                'calculation_first_month', 'calculation_first_year',
            ]);

        $recovered = $this->recoveredBeforeMany(
            $rows->pluck('id')->all(),
            $start->month,
            $start->year
        );

        foreach ($rows as $row) {
            $outstanding = round(
                (float) $row->calculation_amount - ($recovered[$row->id] ?? 0.0),
                2
            );

            if ($outstanding <= 0) {
                continue;
            }

            $existing[] = [
                'id' => $row->id,
                'label' => $row->calculation_particulars
                    ?? $row->calculation_recovery_type,
                'sort' => ($row->calculation_date ?? $row->penalty_date)->format('Y-m-d')
                    . str_pad((string) $row->id, 12, '0', STR_PAD_LEFT),
                'start' => sprintf(
                    '%04d-%02d',
                    $row->calculation_first_year ?? $row->year,
                    $row->calculation_first_month ?? $row->month
                ),
                'balance' => $outstanding,
                'total' => (float) $row->calculation_amount,
            ];
        }

        // The proposed recovery, ordered among the others by its own date.
        $proposed = [
            'id' => null,
            'label' => 'proposed',
            'sort' => \Carbon\Carbon::parse($penaltyDate)->format('Y-m-d')
                . str_pad('9', 12, '9', STR_PAD_LEFT),
            'start' => $start->format('Y-m'),
            'balance' => round($amount, 2),
            'total' => round($amount, 2),
        ];

        $queue = array_merge($existing, [$proposed]);
        usort($queue, fn($a, $b) => strcmp($a['sort'], $b['sort']));

        $proposedKey = array_search(null, array_column($queue, 'id'), true);

        $schedule = [];
        $cursor = $start->copy();

        for ($i = 0; $i < $maxMonths && $queue[$proposedKey]['balance'] > 0; $i++) {
            $period = $cursor->format('Y-m');
            $remaining = $budget;

            foreach ($queue as $k => &$item) {
                if ($remaining <= 0 || $item['balance'] <= 0 || $item['start'] > $period) {
                    continue;
                }

                $take = round(min($item['balance'], $remaining), 2);
                $opening = $item['balance'];
                $item['balance'] = round($item['balance'] - $take, 2);
                $remaining = round($remaining - $take, 2);

                if ($k === $proposedKey && $take > 0) {
                    $schedule[] = [
                        'installment_number' => count($schedule) + 1,
                        'month' => (int) $cursor->month,
                        'year' => (int) $cursor->year,
                        'month_label' => $cursor->format('M Y'),
                        'opening_balance' => $opening,
                        'amount' => $take,
                        'closing_balance' => $item['balance'],
                    ];
                }
            }
            unset($item);

            $cursor->addMonth();
        }

        $last = end($schedule) ?: null;
        $cleared = $queue[$proposedKey]['balance'] <= 0;

        /*
         * The regular monthly figure. The final installment collects
         * the remainder and is usually smaller; the first can also be
         * short when an older recovery is still using part of the
         * budget. The largest is the one that repeats.
         */
        $regular = $schedule
            ? max(array_column($schedule, 'amount'))
            : null;

        return [
            'salary_basis' => $grossSalary,
            'monthly_limit' => $budget,
            'amount' => round($amount, 2),
            'installment_amount' => $regular,
            'number_of_installments' => count($schedule),
            'first_month' => $schedule ? $schedule[0]['month'] : null,
            'first_year' => $schedule ? $schedule[0]['year'] : null,
            'last_month' => $last['month'] ?? null,
            'last_year' => $last['year'] ?? null,
            'date_of_complete_recovery' => $cleared && $last
                ? \Carbon\Carbon::create($last['year'], $last['month'], 1)
                    ->endOfMonth()->toDateString()
                : null,
            'fully_recoverable' => $cleared,
            'schedule' => $schedule,
            'competing_recoveries' => array_values(array_map(
                fn($e) => [
                    'penalty_id' => $e['id'],
                    'particulars' => $e['label'],
                    'outstanding_at_start' => $e['balance'],
                ],
                array_filter($existing, fn($e) => $e['balance'] > 0)
            )),
        ];
    }

    /**
     * Link the period's installments to the payroll row they were
     * deducted on, once that row exists.
     *
     * Payroll is upserted after the recovery is calculated, so the id
     * is only available on the way back out.
     */
    public function attachToPayroll(
        int $employeeId,
        int $month,
        int $year,
        int $payrollId
    ): void {
        LoanRecoveryInstallment::where('employee_id', $employeeId)
            ->where('month', $month)
            ->where('year', $year)
            ->whereNull('payroll_id')
            ->update([
                'payroll_id' => $payrollId,
                'status' => 'deducted',
                'deduction_date' => now()->toDateString(),
            ]);
    }

    /**
     * Has this employee's payroll for the period already been paid?
     */
    private function isLocked(int $employeeId, int $month, int $year): bool
    {
        return Payroll::where('employee_id', $employeeId)
            ->where('month', $month)
            ->where('year', $year)
            ->where('status', 'paid')
            ->exists();
    }

    /**
     * Totals read back from installments that may no longer be recomputed.
     */
    private function frozenTotals(
        int $employeeId,
        int $month,
        int $year,
        float $grossSalary
    ): array {
        $rows = LoanRecoveryInstallment::with('penalty')
            ->where('employee_id', $employeeId)
            ->where('month', $month)
            ->where('year', $year)
            ->whereIn('status', self::COUNTED_STATUSES)
            ->get();

        return [
            'total' => round((float) $rows->sum('installment_amount'), 2),
            'budget' => $this->maximumMonthlyDeduction($grossSalary),
            'carried' => round((float) $rows->sum('closing_balance'), 2),
            'locked' => true,
            'lines' => $rows->map(fn($row) => [
                'penalty_id' => $row->penalty_id,
                'recovery_type' => optional($row->penalty)->calculation_recovery_type,
                'particulars' => optional($row->penalty)->calculation_particulars,
                'reason' => optional($row->penalty)->reason,
                'total_amount' => (float) optional($row->penalty)->calculation_amount,
                'opening_balance' => (float) $row->opening_balance,
                'installment_amount' => (float) $row->installment_amount,
                'closing_balance' => (float) $row->closing_balance,
                'fully_recovered' => (float) $row->closing_balance <= 0,
            ])->all(),
        ];
    }
}
