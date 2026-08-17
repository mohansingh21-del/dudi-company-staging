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
     * Upload Excel file.
     *
     * POST /api/recoveries/bulk-upload
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

        DB::beginTransaction();

        try {

            $file = $request->file('file');

            /*
             * Temporary document ID prevents duplicate document IDs.
             * We replace it after the upload record gets its ID.
             */
            $upload = RecoveryUpload::create([
                'document_id' => 'TEMP-' . uniqid(),
                'file_name' => $file->getClientOriginalName(),
                'uploaded_by' => auth()->id(),
                'status' => 'failed',
                'total_rows' => 0,
                'error_rows' => 0,
            ]);

            /*
             * Now generate DOC-0001 style ID using actual DB ID.
             */
            $upload->update([
                'document_id' => 'DOC-' . str_pad(
                    $upload->id,
                    4,
                    '0',
                    STR_PAD_LEFT
                ),
            ]);

            /*
             * Read Excel and insert into staging table.
             */
            Excel::import(
                new RecoveryImport($upload->id),
                $file
            );

            /*
             * Calculate status.
             */
            $this->refreshUploadStatus($upload);

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'File uploaded successfully.',

                'data' => [
                    'id' => $upload->id,
                    'document_id' => $upload->document_id,
                    'file_name' => $upload->file_name,
                    'status' => $upload->status,
                    'total_rows' => $upload->total_rows,
                    'error_rows' => $upload->error_rows,
                ],
            ], 200);
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Recovery bulk upload failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 500,
                'message' => 'Unable to process recovery file.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Refresh upload status.
     */
    private function refreshUploadStatus(RecoveryUpload $upload): void
    {
        $total = $upload->rows()->count();

        $errors = $upload->rows()
            ->where('is_valid', false)
            ->count();

        $upload->update([
            'total_rows' => $total,
            'error_rows' => $errors,

            'status' => $errors > 0
                ? 'failed'
                : 'success',
        ]);
    }


    /**
     * Upload list / first screen.
     *
     * GET /api/recoveries/uploads
     */
    public function uploads(Request $request)
    {
        $query = RecoveryUpload::query()
            ->with('user')
            ->latest();

        if ($request->filled('year')) {
            $query->whereYear(
                'created_at',
                $request->integer('year')
            );
        }

        if ($request->filled('month')) {
            $query->whereMonth(
                'created_at',
                $request->integer('month')
            );
        }

        if ($request->filled('search')) {

            $search = trim($request->search);

            $query->where(function ($q) use ($search) {

                $q->where(
                    'file_name',
                    'like',
                    "%{$search}%"
                );

                $q->orWhere(
                    'document_id',
                    'like',
                    "%{$search}%"
                );
            });
        }

        $uploads = $query->paginate(
            $request->integer('per_page', 10)
        );

        $uploads->getCollection()->transform(
            function ($upload) {

                return [
                    'id' => $upload->id,

                    'document_id' =>
                    $upload->document_id,

                    'file_name' =>
                    $upload->file_name,

                    'uploaded_by' =>
                    optional($upload->user)->name,

                    'status' =>
                    $upload->status,

                    'total_rows' =>
                    $upload->total_rows,

                    'error_rows' =>
                    $upload->error_rows,

                    'uploaded_at' =>
                    $upload->created_at?->format(
                        'Y-m-d H:i:s'
                    ),
                ];
            }
        );

        return response()->json([
            'status' => 200,
            'data' => $uploads,
        ]);
    }


    /**
     * Preview uploaded Excel.
     *
     * GET /api/recoveries/uploads/{upload}/preview
     */
    public function preview(RecoveryUpload $upload)
    {
        $upload->load('user');

        $rows = $upload->rows()
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 200,

            'data' => [
                'id' => $upload->id,

                'document_id' =>
                $upload->document_id,

                'file_name' =>
                $upload->file_name,

                'uploaded_by' =>
                optional($upload->user)->name,

                'status' =>
                $upload->status,

                'total_rows' =>
                $upload->total_rows,

                'error_rows' =>
                $upload->error_rows,

                /*
                 * Frontend can use this directly.
                 */
                'can_submit' =>
                $upload->error_rows === 0,

                'rows' => $rows,
            ]
        ]);
    }


    /**
     * Update one field in a staging row.
     *
     * PUT /api/recoveries/uploads/rows/{row}
     *
     * Body:
     * {
     *   "field": "name",
     *   "value": "Michael Scott"
     * }
     */
    public function updateRowOLD(
        Request $request,
        RecoveryUploadRow $row
    ) {

        $request->validate([
            'field' => 'required|string',
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

        if (!in_array($request->field, $allowed, true)) {

            return response()->json([
                'status' => 422,
                'message' => 'Invalid field.',
            ], 422);
        }

        /*
         * Convert the incoming value according to field.
         */
        $value = $request->input('value');

        try {

            switch ($request->field) {

                case 'amount':

                    if (
                        $value === null ||
                        $value === '' ||
                        !is_numeric($value)
                    ) {
                        $convertedValue = $value;
                    } else {
                        $convertedValue = (float) $value;
                    }

                    break;

                case 'number_of_installments':

                    if (
                        $value === null ||
                        $value === '' ||
                        !is_numeric($value)
                    ) {
                        $convertedValue = $value;
                    } else {
                        $convertedValue = (int) $value;
                    }

                    break;

                case 'damage_loss_date':
                case 'complete_recovery_date':

                    $convertedValue =
                        $this->parseDate($value);

                    /*
                     * If value was supplied but invalid,
                     * keep null and validation will create error.
                     */
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
                        $this->normalizeYesNo($value);

                    break;

                default:

                    $convertedValue =
                        $value !== null
                        ? trim((string) $value)
                        : null;
            }

            $row->{$request->field} =
                $convertedValue;

            /*
             * Revalidate the entire row.
             */
            $this->validateRow($row);

            $row->save();

            $this->refreshUploadStatus(
                $row->upload
            );

            return response()->json([
                'status' => 200,
                'message' => 'Row updated successfully.',

                'data' => $row->fresh(),

                'upload' => [
                    'id' => $row->upload->id,
                    'status' => $row->upload->status,
                    'total_rows' => $row->upload->total_rows,
                    'error_rows' => $row->upload->error_rows,
                    'can_submit' =>
                    $row->upload->error_rows === 0,
                ],
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'status' => 422,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function updateRow(
        Request $request,
        RecoveryUploadRow $row
    ) {
        $request->validate([
            'field' => 'required|string',
            'value' => 'nullable',
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

        if (!in_array($request->field, $allowed)) {
            return response()->json([
                'status' => 422,
                'message' => 'Invalid field.'
            ], 422);
        }

        $row->{$request->field} = $request->value;

        $this->validateRow($row);

        $row->save();

        // Get upload explicitly
        $upload = $row->upload;

        if (!$upload) {
            return response()->json([
                'status' => 422,
                'message' => 'Upload record not found for this row.',
                'row_id' => $row->id,
                'recovery_upload_id' => $row->recovery_upload_id,
            ], 422);
        }

        $this->refreshUploadStatus($upload);

        return response()->json([
            'status' => 200,
            'message' => 'Row updated successfully.',
            'data' => $row->fresh(),
            'upload' => $upload->fresh(),
        ]);
    }

    /**
     * Validate complete staging row.
     */
    private function validateRow(
        RecoveryUploadRow $row
    ): RecoveryUploadRow {

        $errors = [];
        /*
         * Employee validation.
         */
        $employee = null;

        if (empty(trim((string) $row->employee_code))) {

            $errors['employee_code'][] =
                'Employee code is required.';
        } else {

            $employee = Employee::where(
                'employee_code',
                trim($row->employee_code)
            )->first();

            if (!$employee) {

                $errors['employee_code'][] =
                    'Employee does not exist.';
            }
        }

        /*
         * Name must match employee.
         */
        if (empty(trim((string) $row->name))) {

            $errors['name'][] =
                'Employee name is required.';
        } elseif ($employee) {

            $employeeName =
                strtolower(
                    trim($employee->full_name)
                );

            $uploadedName =
                strtolower(
                    trim($row->name)
                );

            if ($employeeName !== $uploadedName) {

                $errors['name'][] =
                    'Employee name does not match.';
            }
        }

        /*
         * Recovery type.
         */
        $allowedTypes = [
            'damage',
            'loss',
            'fine',
            'advance',
            'loans',
        ];

        $type = strtolower(
            trim((string) $row->recovery_type)
        );

        if (!in_array($type, $allowedTypes, true)) {

            $errors['recovery_type'][] =
                'Invalid recovery type. Allowed values: damage, loss, fine, advance, loans.';
        }

        /*
         * Amount.
         */
        if (
            $row->amount === null ||
            $row->amount === '' ||
            !is_numeric($row->amount)
        ) {

            $errors['amount'][] =
                'Amount must be numeric.';
        } elseif ((float) $row->amount <= 0) {

            $errors['amount'][] =
                'Amount must be greater than zero.';
        }

        /*
         * Show cause.
         */
        $showCause = strtolower(
            trim((string) $row->show_cause_issued)
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
         * Number of installments.
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
         * Damage/Loss date.
         */
        if (
            $row->damage_loss_date === null &&
            !empty($row->damage_loss_date)
        ) {

            $errors['damage_loss_date'][] =
                'Invalid damage/loss date.';
        }

        /*
         * Complete recovery date.
         */
        if (
            $row->complete_recovery_date === null &&
            !empty($row->complete_recovery_date)
        ) {

            $errors['complete_recovery_date'][] =
                'Invalid complete recovery date.';
        }

        /*
         * First Month/Year.
         */
        if (
            !empty($row->first_month_year) &&
            !preg_match(
                '/^\d{4}-(0[1-9]|1[0-2])$/',
                trim($row->first_month_year)
            )
        ) {

            $errors['first_month_year'][] =
                'First Month/Year must be in YYYY-MM format.';
        }

        /*
         * Last Month/Year.
         */
        if (
            !empty($row->last_month_year) &&
            !preg_match(
                '/^\d{4}-(0[1-9]|1[0-2])$/',
                trim($row->last_month_year)
            )
        ) {

            $errors['last_month_year'][] =
                'Last Month/Year must be in YYYY-MM format.';
        }

        /*
         * First period cannot be after last period.
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

        $row->errors =
            empty($errors) ? null : $errors;

        $row->is_valid =
            empty($errors);

        return $row;
    }


    /**
     * Delete staging row.
     *
     * DELETE /api/recoveries/uploads/rows/{row}
     */
    public function deleteRow(
        RecoveryUploadRow $row
    ) {

        $upload = $row->upload;

        $row->delete();

        $this->refreshUploadStatus($upload);

        return response()->json([
            'status' => 200,
            'message' => 'Row deleted successfully.',

            'upload' => [
                'id' => $upload->id,
                'status' => $upload->status,
                'total_rows' => $upload->total_rows,
                'error_rows' => $upload->error_rows,
                'can_submit' =>
                $upload->error_rows === 0,
            ],
        ]);
    }


    /**
     * Submit final data.
     *
     * POST /api/recoveries/uploads/{upload}/submit
     */
    public function submit(
        RecoveryUpload $upload
    ) {

        /*
         * Never trust the previous validation state.
         * Validate every row again before final insertion.
         */
        foreach ($upload->rows as $row) {

            $this->validateRow($row);

            $row->save();
        }

        $this->refreshUploadStatus($upload);

        /*
         * Stop if any error remains.
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
            ], 422);
        }

        /*
         * Prevent duplicate submission.
         */
        if ($upload->status === 'success') {

            $alreadySubmitted =
                Recovery::where(
                    'recovery_upload_id',
                    $upload->id
                )->exists();

            if ($alreadySubmitted) {

                return response()->json([
                    'status' => 422,
                    'message' =>
                    'This recovery file has already been submitted.',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {

            foreach ($upload->rows as $row) {

                $employee = Employee::where(
                    'employee_code',
                    trim($row->employee_code)
                )->first();

                /*
                 * This should never happen because validation
                 * already checked it.
                 */
                if (!$employee) {

                    throw new \Exception(
                        "Employee {$row->employee_code} does not exist."
                    );
                }

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

            $upload->update([
                'status' => 'success',
                'error_rows' => 0,
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
                    $upload->status,
                ],
            ]);
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error(
                'Recovery submission failed',
                [
                    'upload_id' => $upload->id,
                    'message' => $e->getMessage(),
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
     * Parse Excel/date input.
     */
    private function parseDate(int $value): ?string
    {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        try {

            /*
             * Excel serial date.
             */
            if (
                is_numeric($value) &&
                (float) $value > 0
            ) {

                return ExcelDate
                    ::excelToDateTimeObject($value)
                    ->format('Y-m-d');
            }

            /*
             * Normal date.
             */
            return Carbon::parse($value)
                ->format('Y-m-d');
        } catch (\Throwable $e) {

            return null;
        }
    }


    /**
     * Normalize Yes / No.
     */
    private function normalizeYesNo(int $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(
            trim((string) $value)
        );

        if (
            in_array($value, ['yes', 'y', '1', 'true'], true)
        ) {
            return 'yes';
        }

        if (
            in_array($value, ['no', 'n', '0', 'false'], true)
        ) {
            return 'no';
        }

        return $value;
    }
}
