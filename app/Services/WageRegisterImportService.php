<?php

namespace App\Services;

use App\Models\Employee;
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

        foreach ($sheet as $index => $line) {
            $excelRow = $index + 1;

            if ($excelRow <= self::HEADER_ROWS) {
                continue;
            }

            // Trailing blank lines are not errors, they are just the end.
            if ($this->isBlankLine($line)) {
                continue;
            }

            $parsed[] = $this->parseLine($line, $excelRow, $employees, $seenCodes);
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
    protected function parseLine(array $line, int $excelRow, $employees, array &$seenCodes): array
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
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
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
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
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

    protected function trimOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
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
