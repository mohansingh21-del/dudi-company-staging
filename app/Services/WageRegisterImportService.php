<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeePayroll;
use App\Models\WageRegisterReport;
use App\Models\WageRegisterReportRow;
use App\Models\WageRegisterUpload;
use App\Models\WageRegisterUploadRow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Parses an edited Form B spreadsheet back in and validates it cell by cell.
 *
 * Everything lands in the staging tables. No figure from the sheet is written
 * back to employee_payrolls, payrolls, attendance or the wage master: the user
 * is correcting their own printed register, not the system's records.
 */
class WageRegisterImportService
{
    /** The export writes five header rows before the first employee. */
    public const HEADER_ROWS = 5;

    /**
     * How far apart two money figures may be and still count as the same one.
     * The sheet carries two decimals, so anything under a paisa is rounding,
     * not a disagreement.
     */
    protected const MONEY_TOLERANCE = 0.01;

    /** Expected figures by employee id, keyed by month. See expectedLines(). */
    protected $lineCache = [];

    /**
     * Net payment as the register itself calculates it, by employee id.
     *
     * This is the same call the export makes, so a sheet that was downloaded
     * and sent straight back is checked against exactly the figures it was
     * printed from. Where they disagree, attendance or wages moved after the
     * download — which is the case worth catching, because the sheet would
     * otherwise file a stale net.
     *
     * Priced once for the whole month and held for the life of this service.
     * The calculation costs the same whether it answers for one row or five
     * hundred, so doing it per row would turn a single upload into hundreds of
     * query batches.
     */
    protected function expectedLines(int $month, int $year): array
    {
        $key = "{$year}-{$month}";

        if (!isset($this->lineCache[$key])) {
            $register = app(WageRegisterService::class);
            $employees = $register->registerEmployeeQuery($month, $year)->get();

            $byId = $employees->keyBy('id');
            $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
            $restDayCap = LeaveBalanceService::monthlyPaidRestDays();

            $this->lineCache[$key] = [];

            foreach ($register->linesFor($employees, $month, $year) as $id => $line) {
                $this->lineCache[$key][$id] = $this->expectedFrom(
                    $line,
                    $byId->get($id),
                    $daysInMonth,
                    $restDayCap
                );
            }
        }

        return $this->lineCache[$key];
    }

    /** The figures a submitted row is measured against, from a register line. */
    protected function expectedFrom(array $line, $employee, int $daysInMonth, int $restDayCap): array
    {
        $monthlyPay = $employee
            ? EmployeePayroll::monthlyPay($employee->activePayroll)
            : 0.0;

        return [
            'net' => (float) $line['net_payment'],
            'absence' => (float) $line['absence_deduction'],
            'earnings' => (float) $line['total_earnings'],
            'deductions' => (float) $line['total_deductions'],

            // Column 4 and what the month costs, so an edited day count can be
            // priced. The daily rate is the one WageRegisterService prices
            // absence at — monthly pay over the month's own length, not a flat
            // 30 — so a corrected day count moves the money by exactly what the
            // register would have charged for it.
            'days_worked' => (float) $line['days_worked'],
            'per_day' => $daysInMonth > 0 ? $monthlyPay / $daysInMonth : 0.0,

            // Column 4 is capped at the month less its weekly rest days, so it
            // can never print a whole month. Nothing above this is a day the
            // register could have produced.
            'max_days' => (float) max(0, $daysInMonth - $restDayCap),
        ];
    }

    /**
     * What the row should show for a given day count.
     *
     * Column 4 is not free text: it is what the month is priced on. A day taken
     * off it is a day the employee is not paid for, and Form B charges that by
     * shortening the month rather than as a deduction line — so the absence
     * rises and the net falls by the daily rate, exactly as the register would
     * have done had attendance said so in the first place.
     *
     * Measured as a change from the register's own figure rather than from the
     * day count itself, because the two are not in a ratio: column 4 is capped
     * at the month less its rest days, so an employee shown as working 27 of 31
     * days may still be paid for all 31. The delta carries no such ambiguity.
     */
    protected function expectedForDays(array $expected, float $days): array
    {
        $shortfall = round($expected['days_worked'] - $days, 2);
        $cost = round($expected['per_day'] * $shortfall, 2);

        return [
            'absence' => round($expected['absence'] + $cost, 2),

            // Floored for the same reason the register floors it: nothing is
            // ever recovered from an employee through this document.
            'net' => round(max(0, $expected['net'] - $cost), 2),
        ];
    }

    /**
     * The expected net for a single employee.
     *
     * Correcting one cell does not justify pricing every employee on the
     * register, so this runs the same calculation scoped to one row. The
     * whole-month set is reused when the upload path has already built it.
     */
    protected function expectedLineFor(?int $employeeId, int $month, int $year): ?array
    {
        if (!$employeeId) {
            return null;
        }

        $key = "{$year}-{$month}";

        if (isset($this->lineCache[$key])) {
            return $this->lineCache[$key][$employeeId] ?? null;
        }

        $employee = Employee::with(['activePayroll', 'department'])->find($employeeId);

        if (!$employee) {
            return null;
        }

        $line = app(WageRegisterService::class)
            ->linesFor(collect([$employee]), $month, $year)[$employeeId] ?? null;

        return $line ? $this->expectedFrom(
            $line,
            $employee,
            Carbon::create($year, $month, 1)->daysInMonth,
            LeaveBalanceService::monthlyPaidRestDays()
        ) : null;
    }

    /** The sheet's amount columns, for telling a bad cell from a bad sum. */
    protected function amountFields(): array
    {
        return array_column(
            array_filter(WageRegisterUploadRow::COLUMNS, fn($c) => $c[2] === 'amount'),
            0
        );
    }

    /**
     * The cross-column checks a submitted row has to pass.
     *
     * Net Payment is not the user's figure to set. It is what the register
     * calculates from attendance, wages and deductions, and the sheet carries
     * it so the reviewer can see it, not so it can be edited. The other two
     * checks exist because of that: once the net is fixed, the columns either
     * account for it or the sheet is wrong somewhere the user still has to
     * find.
     *
     * @param  array<string,mixed>  $values    the row as it would be stored
     * @param  array|null           $expected  the register's own figures, or
     *                                         null when the employee is not on
     *                                         this month's register and there
     *                                         is nothing to check against
     * @return array<string,string>  field => message
     */
    protected function checkArithmetic(array $values, ?array $expected): array
    {
        $errors = [];

        $num = fn($field) => (float) ($values[$field] ?? 0);
        $sum = fn(array $fields) => round(array_sum(array_map($num, $fields)), 2);
        $rs = fn($amount) => number_format((float) $amount, 2);

        $earnings = round($num('total_earnings'), 2);
        $deductions = round($num('total_deductions'), 2);
        $net = round($num('net_payment'), 2);

        $earningParts = $sum(WageRegisterUploadRow::EARNING_PARTS);
        $deductionParts = $sum(WageRegisterUploadRow::DEDUCTION_PARTS);

        if (abs($earnings - $earningParts) > self::MONEY_TOLERANCE) {
            $errors['total_earnings'] = "Columns 6 to 11 add up to {$rs($earningParts)}, but Total says {$rs($earnings)}.";
        }

        if (abs($deductions - $deductionParts) > self::MONEY_TOLERANCE) {
            $errors['total_deductions'] = "Columns 13 to 19 add up to {$rs($deductionParts)}, but Total says {$rs($deductions)}.";
        }

        if ($expected === null) {
            return $errors;
        }

        // Column 4 is what the month is priced on, so it is checked before any
        // amount: every figure below is measured against the day count on the
        // row, not against the one attendance produced. Editing it is allowed —
        // the money simply has to follow it.
        $days = round((float) ($values['days_worked'] ?? 0), 2);

        if ($days > $expected['max_days'] + self::MONEY_TOLERANCE) {
            $errors['days_worked'] = "No. of days worked cannot be more than "
                . rtrim(rtrim(number_format($expected['max_days'], 2), '0'), '.')
                . " this month — the rest days the month owes always come off the count.";

            // An impossible day count cannot price anything, so the amounts are
            // left alone rather than measured against a figure the register
            // could never have produced.
            return $errors;
        }

        $forDays = $this->expectedForDays($expected, $days);

        if (abs($net - $forDays['net']) > self::MONEY_TOLERANCE) {
            $errors['net_payment'] = "Net Payment is calculated by the register and cannot be changed. "
                . "It should be {$rs($forDays['net'])}"
                . (abs($days - $expected['days_worked']) > self::MONEY_TOLERANCE
                    ? " for " . rtrim(rtrim(number_format($days, 2), '0'), '.') . " days worked"
                    : '')
                . ", not {$rs($net)}.";
        }

        // Form B has no absence column. What the sheet carries back is the net,
        // and commit() reads the gap between (earnings - deductions) and the net
        // as wages for days not worked — see absenceFrom(). So that gap is not
        // free space to absorb an edit: it has to be the absence the register
        // actually calculated.
        //
        // Checking only that the net fits inside the gap would let money be
        // added to an earnings column and silently booked as a deduction the
        // employee never incurred — the register would still add up, and the
        // employee would still be paid the same net, with the difference filed
        // against days they in fact worked.
        //
        // Skipped where the register's own net was floored at 0 because
        // deductions outran earnings: absence and the written-off excess are
        // then indistinguishable, and absenceFrom() does not claim to separate
        // them either.
        $identityHolds = abs(
            ($expected['earnings'] - $expected['deductions'] - $expected['absence']) - $expected['net']
        ) <= self::MONEY_TOLERANCE;

        if ($identityHolds) {
            $available = round($earnings - $deductions, 2);
            $shouldLeave = round($forDays['net'] + $forDays['absence'], 2);
            $drift = round($available - $shouldLeave, 2);

            if (abs($drift) > self::MONEY_TOLERANCE) {
                $message = "Earnings minus deductions leaves {$rs($available)}, but it should leave "
                    . "{$rs($shouldLeave)} — Net Payment {$rs($forDays['net'])}"
                    . ($forDays['absence'] > 0
                        ? " plus {$rs($forDays['absence'])} withheld for days not worked"
                        : ', and nothing is withheld for days not worked')
                    . '. ' . ($drift > 0
                        ? "{$rs($drift)} is unaccounted for: take it off an earnings column, or add it to a deduction column."
                        : "{$rs(abs($drift))} is missing: add it to an earnings column, or take it off a deduction column.");

                // Recorded against one column only. The row is what fails to
                // balance, not either total on its own — both may be correct
                // sums of their own parts — and the message names both remedies
                // itself. Writing it under earnings and deductions alike marked
                // a second cell at the cost of printing the same sentence twice
                // to whoever reads the row's errors.
                //
                // Earnings carries it, unless its own sum is already wrong: a
                // column that is being reported for a different reason is not
                // the one to hang this on, and its message would win the ??
                // anyway. Where both totals already fail their own sum, the
                // drift is a consequence of that and adds nothing.
                if (!isset($errors['total_earnings'])) {
                    $errors['total_earnings'] = $message;
                } elseif (!isset($errors['total_deductions'])) {
                    $errors['total_deductions'] = $message;
                }
            }
        }

        return $errors;
    }

    /**
     * Read, validate and stage a sheet. Replaces any existing staging batch for
     * the month, so a corrected re-upload supersedes the one before it.
     */
    public function import($file, int $month, int $year, ?int $userId = null, ?string $filename = null): WageRegisterUpload
    {
        $sheets = Excel::toArray(null, $file);
        $sheet = $sheets[0] ?? [];

        // Employee codes are matched as trimmed strings: Excel hands back a
        // numeric code like 101 as a float, and some codes contain spaces.
        $employees = Employee::query()
            ->select('id', 'employee_code', 'name', 'surname')
            ->get()
            ->keyBy(fn($e) => $this->normaliseCode($e->employee_code));

        $parsed = [];
        $seenCodes = [];

        // Priced once, before the sweep: every row is checked against the net
        // the register calculates, and that calculation is a fixed set of
        // grouped queries however many rows ask it.
        $expectedLines = $this->expectedLines($month, $year);

        foreach ($sheet as $index => $line) {
            $excelRow = $index + 1;

            if ($excelRow <= self::HEADER_ROWS) {
                continue;
            }

            // Trailing blank lines are not errors, they are just the end.
            if ($this->isBlankLine($line)) {
                continue;
            }

            $parsed[] = $this->parseLine($line, $excelRow, $employees, $seenCodes, $expectedLines);
        }

        return DB::transaction(function () use ($parsed, $month, $year, $userId, $filename) {
            // One staging batch per month; a re-upload replaces it outright.
            WageRegisterUpload::where('month', $month)->where('year', $year)->delete();

            $valid = collect($parsed)->where('is_valid', true)->count();

            $upload = WageRegisterUpload::create([
                'month' => $month,
                'year' => $year,
                'uploaded_by' => $userId,
                'original_filename' => $filename,
                'total_rows' => count($parsed),
                'valid_rows' => $valid,
                'error_rows' => count($parsed) - $valid,
                'status' => (count($parsed) > 0 && $valid === count($parsed)) ? 'ready' : 'pending',
            ]);

            $now = now();

            foreach (array_chunk($parsed, 500) as $chunk) {
                $rows = array_map(function ($row) use ($upload, $now) {
                    $row['upload_id'] = $upload->id;
                    $row['raw_data'] = json_encode($row['raw_data']);
                    $row['errors'] = json_encode($row['errors']);
                    $row['created_at'] = $now;
                    $row['updated_at'] = $now;

                    return $row;
                }, $chunk);

                WageRegisterUploadRow::insert($rows);
            }

            return $upload->fresh();
        });
    }

    /**
     * Wages for days not worked, recovered from a submitted row.
     *
     * Form B has no absence column — see WageRegisterService, which takes it off
     * the net directly — so an edited sheet cannot carry the figure back. What it
     * does carry back is the net, and the net was built as
     *
     *   net = earnings - deductions - absence
     *
     * so absence is what the other three imply. Deriving it rather than
     * recalculating from attendance is deliberate: the review step exists so that
     * what was approved on screen is what gets filed, and a fresh calculation
     * could disagree with a net the user corrected by hand — or with attendance
     * edited since the sheet was exported.
     *
     * Two rows cannot be read exactly, and both are floored to 0 rather than
     * guessed at:
     *
     *   - The net was floored at 0 because deductions outran earnings. Absence
     *     and the written-off excess are then indistinguishable, and the whole
     *     shortfall is reported as unrecovered instead — see
     *     WageRegisterReportRow::unrecoveredDeduction().
     *   - The net was edited upward past earnings minus deductions. Nothing
     *     stored can explain that, and the summary export reports the remainder
     *     as an unaccounted difference rather than hiding it here.
     */
    protected function absenceFrom(float $earnings, float $deductions, float $net): float
    {
        return round(max(0, $earnings - $deductions - $net), 2);
    }

    /**
     * Move a staged sheet into the register proper.
     *
     * The frozen rows are copied from staging exactly as the user left them —
     * no figure is recalculated here. That is the point of the review step: what
     * was approved on screen is what gets filed.
     *
     * The one value not copied is absence, which has no Form B column to come
     * back in. It is derived from the row's own approved figures rather than
     * recalculated — see absenceFrom().
     *
     * The staged batch is then discarded: it was scratch space for the review,
     * and the register is the record from here on.
     *
     * Still writes to nothing else. employee_payrolls, payrolls, attendance and
     * the wage master are untouched by a submission.
     */
    public function commit(WageRegisterUpload $upload, ?int $userId = null, ?string $remarks = null): WageRegisterReport
    {
        $register = app(WageRegisterService::class);

        return DB::transaction(function () use ($upload, $userId, $remarks, $register) {
            $rows = $upload->rows()->orderBy('excel_row')->get();

            // Skill category is not on the sheet, so it is read from the
            // employee for the audit trail on the frozen row.
            $skills = Employee::whereIn('id', $rows->pluck('employee_id')->filter())
                ->pluck('skill_category', 'id');

            $existing = WageRegisterReport::forMonth($upload->month, $upload->year);

            $report = $existing ?: new WageRegisterReport([
                'month' => $upload->month,
                'year' => $upload->year,
                'version' => 0,
            ]);

            $report->version = (int) $report->version + 1;
            $report->generated_by = $userId;
            $report->generated_at = now();
            $report->employee_count = $rows->count();
            $report->total_earnings = round($rows->sum('total_earnings'), 2);
            $report->total_deductions = round($rows->sum('total_deductions'), 2);
            $report->total_net = round($rows->sum('net_payment'), 2);
            $report->wage_rate_snapshot = $register->rateSnapshot($upload->year, $upload->month);
            $report->remarks = $remarks;
            $report->save();

            // Rebuilt from scratch: serial numbers run 1..n over whatever the
            // sheet actually contains, which may not match a previous version.
            $report->rows()->delete();

            $now = now();
            $serial = 1;
            $insert = [];

            foreach ($rows as $row) {
                $earnings = (float) ($row->total_earnings ?? 0);
                $deductions = (float) ($row->total_deductions ?? 0);
                $net = (float) ($row->net_payment ?? 0);

                $insert[] = [
                    'report_id' => $report->id,
                    'employee_id' => $row->employee_id,
                    'serial_no' => $serial++,
                    'employee_code' => $row->employee_code,
                    'employee_name' => $row->employee_name,
                    'skill_category' => $skills[$row->employee_id] ?? null,

                    'rate_of_wage' => $row->rate_of_wage,
                    'days_worked' => $row->days_worked ?? 0,
                    'overtime_hours' => $row->overtime_hours ?? 0,
                    'basic' => $row->basic ?? 0,
                    'special_basic' => $row->special_basic,
                    'dearness_allowance' => $row->dearness_allowance,
                    'overtime_payment' => $row->overtime_payment ?? 0,
                    'hra' => $row->hra,
                    'other_earnings' => $row->other_earnings,
                    'total_earnings' => $earnings,

                    'pf_deduction' => $row->pf_deduction ?? 0,
                    'esic_deduction' => $row->esic_deduction,
                    'society_deduction' => $row->society_deduction,
                    'income_tax' => $row->income_tax,
                    'insurance' => $row->insurance,

                    // Column 18 came back from the sheet as one figure. The
                    // system's own other/mess/penalty split cannot be recovered
                    // from it, so the whole amount is carried as "other" and the
                    // rest are zero — column 18 still prints the same total the
                    // user approved.
                    'other_deduction' => $row->other_deductions ?? 0,
                    'mess_deduction' => 0,
                    'penalty_deduction' => 0,

                    // Absence has no column on Form B, so it cannot come back
                    // from the sheet — but the net that did come back already has
                    // it taken off. Derived from the identity the net was built
                    // on rather than recalculated from attendance, so the register
                    // reconciles against the figures the user actually approved
                    // even where they were edited by hand.
                    'absence_deduction' => $this->absenceFrom($earnings, $deductions, $net),

                    'recoveries' => $row->recoveries,
                    'total_deductions' => $deductions,
                    'net_payment' => $net,
                    'employer_pf_share' => $row->employer_pf_share,

                    'payment_reference' => $row->payment_reference,
                    'payment_date' => $row->payment_date,
                    'remarks' => $row->remarks,

                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($insert, 500) as $chunk) {
                WageRegisterReportRow::insert($chunk);
            }

            // Staging has done its job — the figures now live in the register,
            // which is the record. Keeping a second copy would only invite the
            // two to drift apart, and the report already carries who filed it
            // and when. Rows go with it on the cascade.
            //
            // Inside the transaction, so a failure anywhere above leaves the
            // staged sheet intact for the user to retry.
            $upload->delete();

            return $report->fresh();
        });
    }

    /**
     * Correct one or more cells on a staged row.
     *
     * Runs the same parsing and validation the upload did, so a value typed into
     * the preview is held to exactly the standard the spreadsheet was. A value
     * that fails is rejected and nothing is written — replacing a bad cell with
     * a different bad value would lose what the user was trying to correct.
     *
     * Errors on cells that were not submitted are left alone: fixing one cell
     * must not quietly clear a different one that is still wrong.
     *
     * Only the staging row is touched. Nothing is written to employee_payrolls,
     * payrolls or any other table the original figures came from.
     *
     * @return array{saved: bool, errors: array<string,string>, applied: array}
     */
    public function updateRow(WageRegisterUploadRow $row, array $values): array
    {
        // Two names for one cell, both carrying a value, is not something to
        // resolve by whichever happens to be applied last — one of them would
        // be silently dropped.
        foreach (WageRegisterUploadRow::FIELD_ALIASES as $alias => $field) {
            if (array_key_exists($alias, $values) && array_key_exists($field, $values)
                && (string) $values[$alias] !== (string) $values[$field]) {
                $label = WageRegisterUploadRow::COLUMN_LABELS[$field] ?? $field;

                return [
                    'saved' => false,
                    'errors' => [
                        $field => "\"{$alias}\" and \"{$field}\" are the same column ({$label}), "
                            . 'but different values were sent for each. Send one of them.',
                    ],
                    'applied' => [],
                ];
            }
        }

        // Folded to the column's own name here, so nothing below has to know
        // an alias exists.
        $canonical = [];

        foreach ($values as $field => $value) {
            $canonical[WageRegisterUploadRow::canonicalField($field)] = $value;
        }

        $values = $canonical;

        $types = [];

        foreach (WageRegisterUploadRow::COLUMNS as [$field, , $type]) {
            $types[$field] = $type;
        }

        $errors = $row->errors ?: [];
        $raw = $row->raw_data ?: [];

        // Parsed first, before anything is written: a submitted value that does
        // not pass is refused outright rather than stored as null with an error
        // attached, which would destroy the figure the user is trying to fix.
        $parsed = [];
        $rejected = [];

        foreach ($values as $field => $value) {
            if (!isset($types[$field])) {
                continue;
            }

            switch ($types[$field]) {
                case 'code':
                case 'text':
                    $parsed[$field] = $this->trimOrNull($value);
                    break;

                case 'amount':
                    [$amount, $error] = $this->parseAmount($value, $field);

                    if ($error) {
                        $rejected[$field] = $error;
                    } else {
                        $parsed[$field] = $amount;
                    }
                    break;

                case 'date':
                    [$date, $error] = $this->parseDate($value);

                    if ($error) {
                        $rejected[$field] = $error;
                    } else {
                        $parsed[$field] = $date;
                    }
                    break;
            }
        }

        if ($rejected) {
            return ['saved' => false, 'errors' => $rejected, 'applied' => []];
        }

        $upload = $row->upload;
        $expected = $this->expectedLineFor($row->employee_id, $upload->month, $upload->year);

        // Net Payment is the register's own figure. A screen that resends the
        // whole row unchanged is fine — only an attempt to move it is refused,
        // and refused outright rather than saved and marked, because there is
        // no second column the user could edit to make the new value correct.
        //
        // Priced against the day count in the same request where one was sent,
        // so an edit that changes column 4 and the net together is judged as
        // one change rather than the net being measured against the day count
        // it is replacing.
        if (array_key_exists('net_payment', $parsed) && $expected !== null) {
            $days = array_key_exists('days_worked', $parsed)
                ? (float) $parsed['days_worked']
                : (float) $row->days_worked;

            $target = $this->expectedForDays($expected, $days)['net'];

            if (abs((float) $parsed['net_payment'] - $target) > self::MONEY_TOLERANCE) {
                return [
                    'saved' => false,
                    'errors' => [
                        'net_payment' => 'Net Payment is calculated by the register and cannot be changed. '
                            . 'It is ' . number_format($target, 2) . ' for this employee at '
                            . rtrim(rtrim(number_format($days, 2), '0'), '.') . ' days worked.',
                    ],
                    'applied' => [],
                ];
            }
        }

        foreach ($parsed as $field => $value) {
            $row->{$field} = $value;

            // Keep what was actually typed, the same as the upload does.
            $raw[$field] = is_scalar($values[$field]) ? (string) $values[$field] : null;

            // This cell has been re-judged and passed.
            unset($errors[$field]);
        }

        // Identity is a property of the row rather than of one cell — a corrected
        // code can resolve a duplicate, and a corrected name can only be judged
        // against whichever employee the code now points at.
        $errors = $this->revalidateIdentity($row, $errors);

        // A well-formed value can still be wrong in context: a code that exists
        // but duplicates another row, or a name that contradicts the code. That
        // is a rejection of this edit too, not a note left on the row.
        $contextual = array_intersect_key($errors, $parsed);

        if ($contextual) {
            return ['saved' => false, 'errors' => $contextual, 'applied' => []];
        }

        // Recomputed from scratch rather than patched: an error on column 12 is
        // usually fixed by editing column 6, so it has to clear from 12 when
        // that happens. Unlike a bad cell this is not a rejection — correcting
        // a mismatch often takes a second edit, and refusing the first would
        // leave the user no way to reach a consistent row.
        foreach (WageRegisterUploadRow::ARITHMETIC_FIELDS as $field) {
            unset($errors[$field]);
        }

        $errors = array_merge(
            $errors,
            $this->checkArithmetic($row->only($this->amountFields()), $expected)
        );

        $row->raw_data = $raw;
        $row->errors = $errors;
        $row->is_valid = empty($errors);
        $row->save();

        $this->refreshCounts($row->upload_id);

        return ['saved' => true, 'errors' => [], 'applied' => $parsed];
    }

    /**
     * Drop a row from a staged sheet, for an employee who does not belong on
     * this month's register.
     *
     * Removing a row can clear an error on a different one — a duplicate code is
     * only a duplicate while both rows exist — so identity is re-checked on the
     * rows that were failing, and the ones that changed are reported back.
     *
     * excel_row is left alone on the surviving rows: it names the line in the
     * spreadsheet the user is looking at, so renumbering it would misdirect them.
     * The register's own serial numbers are assigned contiguously at submit.
     *
     * @return array{deleted: array, revalidated: array<int>}
     */
    public function deleteRow(WageRegisterUploadRow $row): array
    {
        $uploadId = $row->upload_id;

        $deleted = [
            'excel_row' => $row->excel_row,
            'employee_code' => $row->employee_code,
            'employee_name' => $row->employee_name,
        ];

        return DB::transaction(function () use ($row, $uploadId, $deleted) {
            $row->delete();

            // Only rows currently failing on identity can be affected, so the
            // sweep stays bounded even on a large sheet.
            $affected = WageRegisterUploadRow::where('upload_id', $uploadId)
                ->where('is_valid', false)
                ->get();

            $revalidated = [];

            foreach ($affected as $candidate) {
                $before = $candidate->errors ?: [];

                if (!isset($before['employee_code']) && !isset($before['employee_name'])) {
                    continue;
                }

                $after = $this->revalidateIdentity($candidate, $before);

                if ($after !== $before) {
                    $candidate->errors = $after;
                    $candidate->is_valid = empty($after);
                    $candidate->save();

                    $revalidated[] = $candidate->excel_row;
                }
            }

            $this->refreshCounts($uploadId);

            return ['deleted' => $deleted, 'revalidated' => $revalidated];
        });
    }

    /**
     * Re-checks the employee code and name for a row, against the rest of its
     * own batch. Errors on other fields are passed through untouched.
     */
    protected function revalidateIdentity(WageRegisterUploadRow $row, array $errors): array
    {
        unset($errors['employee_code'], $errors['employee_name']);

        $code = $this->normaliseCode($row->employee_code);

        if ($code === '') {
            $row->employee_id = null;
            $errors['employee_code'] = 'Employee code is required.';

            return $errors;
        }

        $employee = Employee::all(['id', 'employee_code', 'name', 'surname'])
            ->first(fn($e) => $this->normaliseCode($e->employee_code) === $code);

        if (!$employee) {
            $row->employee_id = null;
            $errors['employee_code'] = "No employee found with code \"{$row->employee_code}\".";

            return $errors;
        }

        $duplicate = WageRegisterUploadRow::where('upload_id', $row->upload_id)
            ->where('id', '!=', $row->id)
            ->where('employee_id', $employee->id)
            ->orderBy('excel_row')
            ->first();

        if ($duplicate) {
            $row->employee_id = $employee->id;
            $errors['employee_code'] = "Duplicate row — code \"{$row->employee_code}\" already appears on row {$duplicate->excel_row}.";

            return $errors;
        }

        $row->employee_id = $employee->id;

        $expected = trim($employee->name . ' ' . $employee->surname);
        $given = trim((string) $row->employee_name);

        if ($given !== '' && strcasecmp($given, $expected) !== 0) {
            $errors['employee_name'] = "Name does not match employee {$employee->employee_code} ({$expected}).";
        }

        return $errors;
    }

    /**
     * Recount a batch after a correction, so the summary and the "can this be
     * submitted yet" flag stay true without the caller having to ask.
     */
    public function refreshCounts(int $uploadId): void
    {
        $upload = WageRegisterUpload::find($uploadId);

        if (!$upload) {
            return;
        }

        $total = $upload->rows()->count();
        $valid = $upload->rows()->where('is_valid', true)->count();

        $upload->total_rows = $total;
        $upload->valid_rows = $valid;
        $upload->error_rows = $total - $valid;

        $upload->status = ($total > 0 && $valid === $total) ? 'ready' : 'pending';

        $upload->save();
    }

    /**
     * One spreadsheet line into a staging row, with per-cell errors.
     */
    protected function parseLine(array $line, int $excelRow, $employees, array &$seenCodes, array $expectedLines = []): array
    {
        $errors = [];
        $raw = [];
        $values = [];

        foreach (WageRegisterUploadRow::COLUMNS as $index => [$field, , $type]) {
            $cell = $line[$index] ?? null;
            $raw[$field] = is_scalar($cell) ? (string) $cell : null;

            switch ($type) {
                case 'code':
                case 'text':
                    $values[$field] = $this->trimOrNull($cell);
                    break;

                case 'amount':
                    [$amount, $error] = $this->parseAmount($cell, $field);
                    $values[$field] = $amount;

                    if ($error) {
                        $errors[$field] = $error;
                    }
                    break;

                case 'date':
                    [$date, $error] = $this->parseDate($cell);
                    $values[$field] = $date;

                    if ($error) {
                        $errors[$field] = $error;
                    }
                    break;
            }
        }

        // ── Employee identity ──
        $code = $this->normaliseCode($values['employee_code'] ?? null);
        $employee = $code === '' ? null : $employees->get($code);

        if ($code === '') {
            $errors['employee_code'] = 'Employee code is required.';
        } elseif (!$employee) {
            $errors['employee_code'] = "No employee found with code \"{$values['employee_code']}\".";
        } elseif (isset($seenCodes[$code])) {
            $errors['employee_code'] = "Duplicate row — code \"{$values['employee_code']}\" already appears on row {$seenCodes[$code]}.";
        } else {
            $seenCodes[$code] = $excelRow;

            // A renamed employee is worth flagging: it usually means the sheet
            // is from a different month, or a row was pasted over.
            $expected = trim($employee->name . ' ' . $employee->surname);
            $given = trim((string) ($values['employee_name'] ?? ''));

            if ($given !== '' && strcasecmp($given, $expected) !== 0) {
                $errors['employee_name'] = "Name does not match employee {$employee->employee_code} ({$expected}).";
            }
        }

        // Checked once identity is settled, because the net a row is measured
        // against belongs to whichever employee the code resolved to.
        //
        // Skipped when a cell did not parse: that cell is already reported, and
        // a totals mismatch caused by the same cell reading as blank would only
        // point the user at the wrong column.
        if (!array_intersect_key($errors, array_flip($this->amountFields()))) {
            $errors += $this->checkArithmetic(
                $values,
                $employee ? ($expectedLines[$employee->id] ?? null) : null
            );
        }

        $values['excel_row'] = $excelRow;
        $values['employee_id'] = $employee->id ?? null;
        $values['raw_data'] = $raw;
        $values['errors'] = $errors;
        $values['is_valid'] = empty($errors);

        return $values;
    }

    /**
     * An amount cell. Blank stays blank — those are the columns the system had
     * no source for and the user may legitimately leave empty. Anything that is
     * not a number after stripping currency formatting is an error rather than
     * being silently coerced to 0.
     */
    protected function parseAmount($value, string $field): array
    {
        if ($this->isBlankValue($value)) {
            return [null, null];
        }

        if (is_numeric($value)) {
            return $this->checkSign((float) $value, $field);
        }

        $cleaned = str_replace(["\u{20B9}", 'Rs.', 'Rs', ',', ' ', "\u{00A0}"], '', trim((string) $value));

        if ($cleaned === '' || !is_numeric($cleaned)) {
            $label = WageRegisterUploadRow::COLUMN_LABELS[$field] ?? $field;

            return [null, "\"{$value}\" is not a valid amount for {$label}. Enter numbers only."];
        }

        return $this->checkSign((float) $cleaned, $field);
    }

    /**
     * Wages and deductions are never negative on the register — a minus sign
     * here is a typing slip, not a credit.
     */
    protected function checkSign(float $amount, string $field): array
    {
        if ($amount < 0) {
            $label = WageRegisterUploadRow::COLUMN_LABELS[$field] ?? $field;

            return [null, "{$label} cannot be negative."];
        }

        return [round($amount, 2), null];
    }

    /**
     * Excel dates come back as serial numbers when the cell is date-formatted
     * and as text when it is not, so both are accepted.
     */
    protected function parseDate($value): array
    {
        if ($this->isBlankValue($value)) {
            return [null, null];
        }

        if (is_numeric($value)) {
            try {
                return [
                    Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value))
                        ->toDateString(),
                    null,
                ];
            } catch (\Throwable $e) {
                return [null, "\"{$value}\" is not a valid Date of Payment."];
            }
        }

        try {
            return [Carbon::parse(trim((string) $value))->toDateString(), null];
        } catch (\Throwable $e) {
            return [null, "\"{$value}\" is not a valid Date of Payment. Use YYYY-MM-DD."];
        }
    }

    /**
     * How a sheet writes "nothing here". A register printed for signature
     * carries these in the columns that do not apply to an employee, and they
     * come back on the upload and from the correction screen, so they are read
     * as an empty cell rather than as a value that failed to parse.
     *
     * Only the cell is blanked — a column that is required is still required,
     * and reports itself missing rather than accepting the placeholder.
     */
    protected const BLANK_PLACEHOLDERS = [
        'n/a', 'n.a.', 'na', '#n/a', 'nil', 'null', 'none', '-', '--', '---',
        "\u{2013}", "\u{2014}",
    ];

    /** True for an empty cell, or for a placeholder standing in for one. */
    protected function isBlankValue($value): bool
    {
        if ($value === null) {
            return true;
        }

        // A number is never a placeholder, and casting one to a string here
        // would let a stray format make it look like one.
        if (!is_string($value)) {
            return false;
        }

        $trimmed = trim($value);

        return $trimmed === ''
            || in_array(mb_strtolower($trimmed), self::BLANK_PLACEHOLDERS, true);
    }

    protected function trimOrNull($value): ?string
    {
        if ($this->isBlankValue($value)) {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * Excel returns a numeric code as a float ("101" becomes 101.0), and codes
     * in this data contain spaces, so both sides of a match are normalised.
     */
    protected function normaliseCode($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) && floor($value) == $value) {
            $value = (int) $value;
        }

        return strtoupper(preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    protected function isBlankLine(array $line): bool
    {
        foreach ($line as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
