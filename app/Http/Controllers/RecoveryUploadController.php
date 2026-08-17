<?php

namespace App\Http\Controllers;

use App\Imports\RecoveryImport;
use App\Models\Employee;
use App\Models\Recovery;
use App\Models\RecoveryUpload;
use App\Models\RecoveryUploadRow;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class RecoveryUploadController extends Controller
{
    /**
     * =========================================================
     * BULK UPLOAD
     * =========================================================
     *
     * POST /api/recoveries/bulk-upload
     *
     * IMPORTANT:
     * This ONLY creates recovery_uploads and recovery_upload_rows.
     *
     * It does NOT insert anything into recoveries.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
                'max:10240',
            ],
        ]);

        $file = $request->file('file');

        /*
         * Generate SHA-256 hash of uploaded file.
         */
        $fileHash = hash_file(
            'sha256',
            $file->getRealPath()
        );

        /*
         * Prevent exact same file from being uploaded twice.
         */
        $existingUpload = RecoveryUpload::where(
            'file_hash',
            $fileHash
        )->first();

        if ($existingUpload) {

            return response()->json([
                'status' => 422,
                'message' =>
                'This file has already been uploaded.',
                'data' => [
                    'upload_id' =>
                    $existingUpload->id,

                    'document_id' =>
                    $existingUpload->document_id,

                    'status' =>
                    $existingUpload->status,
                ],
            ], 422);
        }

        DB::beginTransaction();

        try {

            /*
             * Create upload header.
             *
             * "failed" is the initial state.
             * It will become "ready" if all rows pass validation.
             */
            $upload = RecoveryUpload::create([
                'document_id' =>
                'TEMP-' . uniqid(),

                'file_name' =>
                $file->getClientOriginalName(),

                'file_hash' =>
                $fileHash,

                'uploaded_by' =>
                auth()->id(),

                'status' =>
                'failed',

                'total_rows' =>
                0,

                'error_rows' =>
                0,
            ]);

            /*
             * Generate document ID.
             */
            $upload->update([
                'document_id' =>
                'DOC-' . str_pad(
                    $upload->id,
                    4,
                    '0',
                    STR_PAD_LEFT
                ),
            ]);

            /*
             * Import ONLY into staging table.
             */
            Excel::import(
                new RecoveryImport(
                    $upload->id
                ),
                $file
            );

            /*
             * Calculate validation result.
             */
            $this->refreshUploadStatus(
                $upload
            );

            DB::commit();

            $upload->refresh();

            return response()->json([
                'status' => 200,

                'message' =>
                'File uploaded and validated successfully.',

                'data' => [
                    'id' =>
                    $upload->id,

                    'document_id' =>
                    $upload->document_id,

                    'file_name' =>
                    $upload->file_name,

                    'status' =>
                    $upload->status,

                    'total_rows' =>
                    $upload->total_rows,

                    'error_rows' =>
                    $upload->error_rows,

                    'can_submit' =>
                    $upload->status === 'ready',
                ],
            ], 200);
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error(
                'Recovery bulk upload failed',
                [
                    'message' =>
                    $e->getMessage(),

                    'trace' =>
                    $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'status' => 500,

                'message' =>
                'Unable to process recovery file.',

                'error' =>
                $e->getMessage(),

            ], 500);
        }
    }


    /**
     * =========================================================
     * REFRESH UPLOAD STATUS
     * =========================================================
     */
    private function refreshUploadStatusOLD(
        RecoveryUpload $upload
    ): void {

        $total = $upload
            ->rows()
            ->count();

        $errors = $upload
            ->rows()
            ->where('is_valid', false)
            ->count();

        /*
         * READY means:
         * - all staging rows are valid
         * - data has NOT been submitted yet
         */
        $status =
            $errors > 0
            ? 'failed'
            : 'ready';

        $upload->update([
            'total_rows' =>
            $total,

            'error_rows' =>
            $errors,

            'status' =>
            $status,
        ]);
    }
    private function refreshUploadStatus(RecoveryUpload $upload): void
    {
        $total = $upload->rows()->count();

        $errors = $upload->rows()
            ->where('is_valid', false)
            ->count();

        /*
     * Don't change an already successfully submitted upload.
     */
        if ($upload->status === 'success') {
            return;
        }

        $upload->update([
            'total_rows' => $total,
            'error_rows' => $errors,

            'status' => $errors > 0
                ? 'failed'
                : 'ready',
        ]);
    }

    /**
     * =========================================================
     * GET UPLOADS
     * =========================================================
     *
     * GET /api/recoveries/uploads
     */
    /**
     * Get recovery upload list.
     *
     * GET /api/recovery-uploads
     *
     * Optional filters:
     * ?year=2026
     * ?month=8
     * ?status=success
     * ?search=Penalty
     * ?per_page=10
     */
    public function uploads(Request $request)
    {
        $query = RecoveryUpload::query()
            ->with('user')
            ->latest('created_at');

        /*
     * Filter by year.
     */
        if ($request->filled('year')) {
            $query->whereYear(
                'created_at',
                (int) $request->input('year')
            );
        }

        /*
     * Filter by month.
     */
        if ($request->filled('month')) {
            $query->whereMonth(
                'created_at',
                (int) $request->input('month')
            );
        }

        /*
     * Filter by upload status.
     *
     * success = submitted successfully
     * failed  = validation errors
     */
        if ($request->filled('status')) {

            $allowedStatuses = [
                'success',
                'failed',
            ];

            $status = strtolower(
                trim($request->input('status'))
            );

            if (in_array($status, $allowedStatuses, true)) {
                $query->where('status', $status);
            }
        }

        /*
     * Search by:
     * - document ID
     * - file name
     */
        if ($request->filled('search')) {

            $search = trim(
                $request->input('search')
            );

            $query->where(function ($q) use ($search) {

                $q->where(
                    'document_id',
                    'like',
                    "%{$search}%"
                );

                $q->orWhere(
                    'file_name',
                    'like',
                    "%{$search}%"
                );
            });
        }

        /*
     * Pagination.
     */
        $perPage = (int) $request->input(
            'per_page',
            10
        );

        /*
     * Prevent unreasonable pagination values.
     */
        $perPage = max(
            1,
            min($perPage, 100)
        );

        $uploads = $query->paginate(
            $perPage
        );

        /*
     * Transform response.
     */
        $uploads->getCollection()->transform(
            function (RecoveryUpload $upload) {

                return [

                    'id' =>
                    $upload->id,

                    'document_id' =>
                    $upload->document_id,

                    'file_name' =>
                    $upload->file_name,

                    'uploaded_by' =>
                    optional($upload->user)->name,

                    'uploaded_by_id' =>
                    $upload->uploaded_by,

                    'status' =>
                    $upload->status,

                    'total_rows' =>
                    $upload->total_rows,

                    'error_rows' =>
                    $upload->error_rows,

                    /*
                 * True only when every row is valid.
                 * This does NOT mean data was inserted
                 * into recoveries table.
                 */
                    'can_submit' =>
                    $upload->status === 'success'
                        &&
                        $upload->error_rows === 0,

                    /*
                 * Whether this upload has already
                 * been transferred to recoveries table.
                 */
                    'is_submitted' =>
                    Recovery::where(
                        'recovery_upload_id',
                        $upload->id
                    )->exists(),

                    'uploaded_at' =>
                    $upload->created_at
                        ? $upload->created_at
                        ->format('Y-m-d H:i:s')
                        : null,

                    'month' =>
                    $upload->created_at
                        ? $upload->created_at
                        ->format('F Y')
                        : null,

                    'year' =>
                    $upload->created_at
                        ? $upload->created_at->year
                        : null,

                    'month_number' =>
                    $upload->created_at
                        ? $upload->created_at->month
                        : null,
                ];
            }
        );

        return response()->json([
            'status' => 200,
            'data' => $uploads,
        ]);
    }


    /**
     * =========================================================
     * PREVIEW
     * =========================================================
     *
     * GET /api/recoveries/uploads/{upload}/preview
     */
    public function preview(
        RecoveryUpload $upload
    ) {

        $upload->load('user');

        $rows = $upload
            ->rows()
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 200,

            'data' => [

                'id' =>
                $upload->id,

                'document_id' =>
                $upload->document_id,

                'file_name' =>
                $upload->file_name,

                'uploaded_by' =>
                optional(
                    $upload->user
                )->name,

                'status' =>
                $upload->status,

                'total_rows' =>
                $upload->total_rows,

                'error_rows' =>
                $upload->error_rows,

                /*
                 * Only READY upload can be submitted.
                 */
                'can_submit' =>
                $upload->status === 'ready',

                'submitted' =>
                $upload->status === 'submitted',

                'rows' =>
                $rows,
            ]
        ]);
    }


    /**
     * =========================================================
     * UPDATE ROW
     * =========================================================
     *
     * PUT /api/recoveries/uploads/rows/{row}
     */
    public function updateRow(
        Request $request,
        RecoveryUploadRow $row
    ) {

        /*
         * Do not allow editing after submission.
         */
        if (
            $row->upload &&
            $row->upload->status === 'submitted'
        ) {

            return response()->json([
                'status' => 422,
                'message' =>
                'Submitted upload cannot be edited.',
            ], 422);
        }

        $request->validate([
            'field' =>
            'required|string',

            'value' =>
            'nullable',
        ]);

        $allowed = [
            'employee_code',
            'name',
            'recovery_type',
            'particulars',
            'damage_loss_date',
            'amount',
            'show_cause_issued',
            'explanation_witness',
            'number_of_installments',
            'first_month_year',
            'last_month_year',
            'complete_recovery_date',
            'remarks',
        ];

        $field = $request->input('field');

        if (!in_array(
            $field,
            $allowed,
            true
        )) {

            return response()->json([
                'status' => 422,
                'message' =>
                'Invalid field.',
            ], 422);
        }

        /*
         * Normalize value according to field.
         */
        $value = $request->input('value');

        switch ($field) {

            case 'amount':

                if (
                    $value === null ||
                    $value === '' ||
                    !is_numeric($value)
                ) {

                    $convertedValue =
                        $value;
                } else {

                    $convertedValue =
                        (float) $value;
                }

                break;

            case 'number_of_installments':

                if (
                    $value === null ||
                    $value === '' ||
                    !is_numeric($value)
                ) {

                    $convertedValue =
                        $value;
                } else {

                    $convertedValue =
                        (int) $value;
                }

                break;

            case 'damage_loss_date':
            case 'complete_recovery_date':

                if (
                    $value === null ||
                    $value === ''
                ) {

                    $convertedValue = null;
                } else {

                    $convertedValue =
                        $this->parseDate(
                            $value
                        );
                }

                break;

            case 'first_month_year':
            case 'last_month_year':

                $convertedValue =
                    $value !== null
                    ? trim((string) $value)
                    : null;

                break;

            case 'show_cause_issued':

                $convertedValue =
                    $this->normalizeYesNo(
                        $value
                    );

                break;

            default:

                $convertedValue =
                    $value !== null
                    ? trim((string) $value)
                    : null;
        }

        $row->{$field} =
            $convertedValue;

        /*
         * Validate entire row again.
         */
        $this->validateRow($row);

        $row->save();

        $upload = $row->upload;

        if (!$upload) {

            return response()->json([
                'status' => 422,
                'message' =>
                'Upload record not found.',
            ], 422);
        }

        /*
         * Recalculate status.
         */
        $this->refreshUploadStatus(
            $upload
        );

        $row->refresh();
        $upload->refresh();

        return response()->json([
            'status' => 200,

            'message' =>
            'Row updated successfully.',

            'data' =>
            $row,

            'upload' => [
                'id' =>
                $upload->id,

                'status' =>
                $upload->status,

                'total_rows' =>
                $upload->total_rows,

                'error_rows' =>
                $upload->error_rows,

                'can_submit' =>
                $upload->status === 'ready',
            ],
        ]);
    }


    /**
     * =========================================================
     * VALIDATE COMPLETE ROW
     * =========================================================
     */
    private function validateRow(
        RecoveryUploadRow $row
    ): RecoveryUploadRow {

        $errors = [];

        /*
         * EMPLOYEE
         */
        $employee = null;

        $employeeCode = trim(
            (string) $row->employee_code
        );

        if ($employeeCode === '') {

            $errors['employee_code'][] =
                'Employee code is required.';
        } else {

            $employee = Employee::where(
                'employee_code',
                $employeeCode
            )->first();

            if (!$employee) {

                $errors['employee_code'][] =
                    'Employee does not exist.';
            }
        }

        /*
         * NAME
         */
        $name = trim(
            (string) $row->name
        );

        if ($name === '') {

            $errors['name'][] =
                'Employee name is required.';
        } elseif (!$employee) {

            $errors['name'][] =
                'Employee name cannot be verified because the employee code does not exist.';
        } else {

            $employeeName = strtolower(
                trim($employee->full_name)
            );

            $uploadedName = strtolower(
                trim($name)
            );

            if (
                $employeeName !==
                $uploadedName
            ) {

                $errors['name'][] =
                    'Employee name does not match.';
            }
        }

        /*
         * RECOVERY TYPE
         */
        $allowedTypes = [
            'damage',
            'loss',
            'fine',
            'advance',
            'loans',
        ];

        $type = strtolower(
            trim(
                (string) $row->recovery_type
            )
        );

        if (!in_array(
            $type,
            $allowedTypes,
            true
        )) {

            $errors['recovery_type'][] =
                'Invalid recovery type. Allowed values: damage, loss, fine, advance, loans.';
        }

        /*
         * AMOUNT
         */
        if (
            $row->amount === null ||
            $row->amount === '' ||
            !is_numeric($row->amount)
        ) {

            $errors['amount'][] =
                'Amount must be numeric.';
        } elseif (
            (float) $row->amount <= 0
        ) {

            $errors['amount'][] =
                'Amount must be greater than zero.';
        }

        /*
         * SHOW CAUSE
         */
        $showCause = strtolower(
            trim(
                (string) $row->show_cause_issued
            )
        );

        if (!in_array(
            $showCause,
            ['yes', 'no'],
            true
        )) {

            $errors['show_cause_issued'][] =
                'Show cause issued must be Yes or No.';
        }

        /*
         * INSTALLMENTS
         */
        if (
            $row->number_of_installments !== null &&
            $row->number_of_installments !== ''
        ) {

            if (
                !is_numeric(
                    $row->number_of_installments
                )
            ) {

                $errors['number_of_installments'][] =
                    'Number of installments must be numeric.';
            } elseif (
                (int) $row->number_of_installments <= 0
            ) {

                $errors['number_of_installments'][] =
                    'Number of installments must be greater than zero.';
            }
        }

        /*
         * DAMAGE DATE
         *
         * Because the database field is already normalized,
         * check whether it contains a valid date value.
         */
        if (
            $row->damage_loss_date !== null &&
            !$this->isValidDateValue(
                $row->damage_loss_date
            )
        ) {

            $errors['damage_loss_date'][] =
                'Invalid damage/loss date.';
        }

        /*
         * COMPLETE DATE
         */
        if (
            $row->complete_recovery_date !== null &&
            !$this->isValidDateValue(
                $row->complete_recovery_date
            )
        ) {

            $errors['complete_recovery_date'][] =
                'Invalid complete recovery date.';
        }

        /*
         * FIRST MONTH
         */
        if (
            !empty($row->first_month_year) &&
            !preg_match(
                '/^\d{4}-(0[1-9]|1[0-2])$/',
                trim(
                    $row->first_month_year
                )
            )
        ) {

            $errors['first_month_year'][] =
                'First Month/Year must be in YYYY-MM format.';
        }

        /*
         * LAST MONTH
         */
        if (
            !empty($row->last_month_year) &&
            !preg_match(
                '/^\d{4}-(0[1-9]|1[0-2])$/',
                trim(
                    $row->last_month_year
                )
            )
        ) {

            $errors['last_month_year'][] =
                'Last Month/Year must be in YYYY-MM format.';
        }

        /*
         * PERIOD
         */
        if (
            empty($errors['first_month_year']) &&
            empty($errors['last_month_year']) &&
            !empty($row->first_month_year) &&
            !empty($row->last_month_year)
        ) {

            if (
                $row->first_month_year >
                $row->last_month_year
            ) {

                $errors['last_month_year'][] =
                    'Last Month/Year cannot be before First Month/Year.';
            }
        }

        /*
         * SAVE ERRORS
         */
        $row->errors =
            empty($errors)
            ? null
            : $errors;

        $row->is_valid =
            empty($errors);

        return $row;
    }


    /**
     * Check date value.
     */
    private function isValidDateValue(
        mixed $value
    ): bool {

        if ($value === null || $value === '') {
            return true;
        }

        try {

            Carbon::parse($value);

            return true;
        } catch (\Throwable $e) {

            return false;
        }
    }


    /**
     * =========================================================
     * DELETE STAGING ROW
     * =========================================================
     */
    public function deleteRow(
        RecoveryUploadRow $row
    ) {

        $upload = $row->upload;

        if (!$upload) {

            return response()->json([
                'status' => 404,
                'message' =>
                'Upload not found.',
            ], 404);
        }

        if (
            $upload->status === 'submitted'
        ) {

            return response()->json([
                'status' => 422,
                'message' =>
                'Submitted upload cannot be modified.',
            ], 422);
        }

        $row->delete();

        $this->refreshUploadStatus(
            $upload
        );

        $upload->refresh();

        return response()->json([
            'status' => 200,

            'message' =>
            'Row deleted successfully.',

            'upload' => [
                'id' =>
                $upload->id,

                'status' =>
                $upload->status,

                'total_rows' =>
                $upload->total_rows,

                'error_rows' =>
                $upload->error_rows,

                'can_submit' =>
                $upload->status === 'ready',
            ],
        ]);
    }


    /**
     * =========================================================
     * SUBMIT
     * =========================================================
     *
     * POST /api/recoveries/uploads/{upload}/submit
     *
     * THIS IS THE ONLY PLACE WHERE DATA ENTERS recoveries.
     */
    public function submit(RecoveryUpload $upload)
    {
        /*
     * Prevent duplicate submission.
     */
        if ($upload->status === 'success') {

            return response()->json([
                'status' => 422,
                'message' =>
                'This recovery file has already been submitted.',
            ], 422);
        }

        /*
     * Get all staging rows.
     */
        $rows = $upload->rows()
            ->orderBy('id')
            ->get();

        /*
     * Revalidate every row.
     */
        foreach ($rows as $row) {

            $this->validateRow($row);

            $row->save();
        }

        /*
     * Recalculate counts.
     */
        $totalRows = $upload->rows()->count();

        $errorRows = $upload->rows()
            ->where('is_valid', false)
            ->count();

        /*
     * Update upload counters.
     */
        $upload->update([
            'total_rows' => $totalRows,
            'error_rows' => $errorRows,
        ]);

        /*
     * If there are errors, DO NOT insert anything
     * into the recoveries table.
     */
        if ($errorRows > 0) {

            $upload->update([
                'status' => 'failed',
            ]);

            return response()->json([
                'status' => 422,

                'message' =>
                'Fix remaining errors before submitting.',

                'data' => [
                    'upload_id' =>
                    $upload->id,

                    'document_id' =>
                    $upload->document_id,

                    'status' =>
                    'failed',

                    'total_rows' =>
                    $totalRows,

                    'error_rows' =>
                    $errorRows,

                    'can_submit' =>
                    false,
                ],
            ], 422);
        }

        /*
     * All rows are valid.
     *
     * Mark as ready BEFORE actual submission.
     */
        $upload->update([
            'status' => 'ready',
            'error_rows' => 0,
        ]);

        DB::beginTransaction();

        try {

            foreach ($rows as $row) {

                /*
             * Find employee.
             */
                $employee = Employee::where(
                    'employee_code',
                    trim($row->employee_code)
                )->first();

                if (!$employee) {

                    throw new \Exception(
                        "Employee {$row->employee_code} does not exist."
                    );
                }

                /*
             * Verify employee name again.
             */
                if (
                    strtolower(trim($employee->full_name))
                    !==
                    strtolower(trim($row->name))
                ) {

                    throw new \Exception(
                        "Employee name does not match for {$row->employee_code}."
                    );
                }

                /*
             * Insert into MAIN recoveries table.
             */
                Recovery::create([

                    'recovery_upload_id' =>
                    $upload->id,

                    'employee_id' =>
                    $employee->id,

                    'employee_code' =>
                    $employee->employee_code,

                    'employee_name' =>
                    $employee->full_name,

                    'recovery_type' =>
                    strtolower(
                        trim($row->recovery_type)
                    ),

                    'particulars' =>
                    $row->particulars,

                    'damage_loss_date' =>
                    $row->damage_loss_date,

                    'amount' =>
                    $row->amount,

                    'show_cause_issued' =>
                    strtolower(
                        trim($row->show_cause_issued)
                    ) === 'yes',

                    'explanation_witness' =>
                    $row->explanation_witness,

                    'number_of_installments' =>
                    $row->number_of_installments,

                    'first_month_year' =>
                    $row->first_month_year,

                    'last_month_year' =>
                    $row->last_month_year,

                    'complete_recovery_date' =>
                    $row->complete_recovery_date,

                    'remarks' =>
                    $row->remarks,
                ]);
            }

            /*
         * IMPORTANT:
         *
         * Only AFTER all rows have successfully
         * been inserted into recoveries,
         * change status to success.
         */
            $upload->update([
                'status' => 'success',
                'error_rows' => 0,
                'total_rows' => $totalRows,
            ]);

            DB::commit();

            return response()->json([
                'status' => 200,

                'message' =>
                'Recovery data submitted successfully.',

                'data' => [
                    'upload_id' =>
                    $upload->id,

                    'document_id' =>
                    $upload->document_id,

                    'status' =>
                    'success',

                    'total_rows' =>
                    $totalRows,

                    'error_rows' =>
                    0,

                    'can_submit' =>
                    false,
                ],
            ], 200);
        } catch (\Throwable $e) {

            DB::rollBack();

            /*
         * Keep upload in ready state because
         * validation passed but submission failed.
         */
            $upload->update([
                'status' => 'ready',
            ]);

            Log::error(
                'Recovery submission failed',
                [
                    'upload_id' =>
                    $upload->id,

                    'message' =>
                    $e->getMessage(),

                    'trace' =>
                    $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'status' => 500,

                'message' =>
                'Unable to submit recovery data.',

                'error' =>
                $e->getMessage(),
            ], 500);
        }
    }
    public function submitOLD(
        RecoveryUpload $upload
    ) {

        /*
         * Prevent duplicate submission.
         */
        if (
            $upload->status === 'submitted'
        ) {

            return response()->json([
                'status' => 422,

                'message' =>
                'This recovery file has already been submitted.',
            ], 422);
        }

        /*
         * Revalidate EVERY row.
         *
         * Never trust the previous validation state.
         */
        $rows = $upload
            ->rows()
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {

            $this->validateRow($row);

            $row->save();
        }

        /*
         * Refresh validation status.
         */
        $this->refreshUploadStatus(
            $upload
        );

        $upload->refresh();

        /*
         * Stop if errors remain.
         */
        if (
            $upload->error_rows > 0
        ) {

            return response()->json([
                'status' => 422,

                'message' =>
                'Fix remaining errors before submitting.',

                'error_rows' =>
                $upload->error_rows,

                'can_submit' =>
                false,
            ], 422);
        }

        /*
         * There must be at least one row.
         */
        if ($upload->total_rows === 0) {

            return response()->json([
                'status' => 422,

                'message' =>
                'Cannot submit an empty recovery file.',
            ], 422);
        }

        /*
         * =====================================================
         * ONLY NOW INSERT INTO recoveries
         * =====================================================
         */
        DB::beginTransaction();

        try {

            foreach ($rows as $row) {

                /*
                 * Find employee again.
                 */
                $employee = Employee::where(
                    'employee_code',
                    trim($row->employee_code)
                )->first();

                if (!$employee) {

                    throw new \Exception(
                        "Employee {$row->employee_code} does not exist."
                    );
                }

                /*
                 * Double-check employee name.
                 */
                if (
                    strtolower(
                        trim($employee->full_name)
                    ) !==
                    strtolower(
                        trim($row->name)
                    )
                ) {

                    throw new \Exception(
                        "Employee name does not match for {$row->employee_code}."
                    );
                }

                /*
                 * IMPORTANT:
                 * This is the actual insertion into recoveries.
                 */
                Recovery::create([

                    'recovery_upload_id' =>
                    $upload->id,

                    'employee_id' =>
                    $employee->id,

                    'employee_code' =>
                    $employee->employee_code,

                    'employee_name' =>
                    $employee->full_name,

                    'recovery_type' =>
                    strtolower(
                        trim(
                            $row->recovery_type
                        )
                    ),

                    'particulars' =>
                    $row->particulars,

                    'damage_loss_date' =>
                    $row->damage_loss_date,

                    'amount' =>
                    $row->amount,

                    'show_cause_issued' =>
                    strtolower(
                        trim(
                            $row->show_cause_issued
                        )
                    ) === 'yes',

                    'explanation_witness' =>
                    $row->explanation_witness,

                    'number_of_installments' =>
                    $row->number_of_installments,

                    'first_month_year' =>
                    $row->first_month_year,

                    'last_month_year' =>
                    $row->last_month_year,

                    'complete_recovery_date' =>
                    $row->complete_recovery_date,

                    'remarks' =>
                    $row->remarks,
                ]);
            }

            /*
             * Mark upload as submitted.
             */
            $upload->update([
                'status' =>
                'submitted',

                'error_rows' =>
                0,
            ]);

            DB::commit();

            $upload->refresh();

            return response()->json([
                'status' => 200,

                'message' =>
                'Recovery data submitted successfully.',

                'data' => [
                    'upload_id' =>
                    $upload->id,

                    'document_id' =>
                    $upload->document_id,

                    'status' =>
                    $upload->status,

                    'total_rows' =>
                    $upload->total_rows,

                    'error_rows' =>
                    $upload->error_rows,
                ],
            ], 200);
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error(
                'Recovery submission failed',
                [
                    'upload_id' =>
                    $upload->id,

                    'message' =>
                    $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => 500,

                'message' =>
                'Unable to submit recovery data.',

                'error' =>
                $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Parse date.
     */
    private function parseDate(
        mixed $value
    ): ?string {

        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        try {

            if (
                is_numeric($value) &&
                (float) $value > 0
            ) {

                return ExcelDate
                    ::excelToDateTimeObject(
                        $value
                    )
                    ->format('Y-m-d');
            }

            return Carbon::parse(
                $value
            )->format('Y-m-d');
        } catch (\Throwable $e) {

            return null;
        }
    }


    /**
     * Normalize Yes/No.
     */
    private function normalizeYesNo(
        mixed $value
    ): ?string {

        if ($value === null) {
            return null;
        }

        $value = strtolower(
            trim((string) $value)
        );

        if (in_array(
            $value,
            ['yes', 'y', '1', 'true'],
            true
        )) {
            return 'yes';
        }

        if (in_array(
            $value,
            ['no', 'n', '0', 'false'],
            true
        )) {
            return 'no';
        }

        return $value;
    }
}
