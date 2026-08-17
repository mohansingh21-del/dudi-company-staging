<?php

namespace App\Http\Controllers;

use App\Models\Recovery;
use App\Models\RecoveryUpload;
use Illuminate\Http\Request;

class RecoveryController extends Controller
{
    /**
     * =========================================================
     * LEVEL 1 - FINAL RECOVERY DOCUMENT REGISTER
     * =========================================================
     *
     * GET /api/v1/admin/recoveries
     *
     * Examples:
     *
     * GET /api/v1/admin/recoveries
     * GET /api/v1/admin/recoveries?year=2026
     * GET /api/v1/admin/recoveries?year=2026&month=8
     * GET /api/v1/admin/recoveries?search=Penalty
     * GET /api/v1/admin/recoveries?upload_id=1
     */
    public function index(Request $request)
    {
        /*
         * Only successfully submitted uploads should
         * appear in the final recovery register.
         */
        $query = RecoveryUpload::query()
            ->with('user')
            ->where('status', 'success');

        /*
         * YEAR FILTER
         */
        if ($request->filled('year')) {

            $query->whereYear(
                'created_at',
                (int) $request->input('year')
            );
        }

        /*
         * MONTH FILTER
         */
        if ($request->filled('month')) {

            $query->whereMonth(
                'created_at',
                (int) $request->input('month')
            );
        }

        /*
         * SPECIFIC UPLOAD
         */
        if ($request->filled('upload_id')) {

            $query->where(
                'id',
                (int) $request->input('upload_id')
            );
        }

        /*
         * SEARCH DOCUMENT
         */
        if ($request->filled('search')) {

            $search = trim(
                $request->input('search')
            );

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

        /*
         * PAGINATION
         */
        $uploads = $query
            ->latest('created_at')
            ->paginate(
                (int) $request->input(
                    'per_page',
                    10
                )
            );

        /*
         * FORMAT DOCUMENT DATA
         */
        $uploads
            ->getCollection()
            ->transform(function ($upload) {

                return [

                    'id' =>
                    $upload->id,

                    'document_id' =>
                    $upload->document_id,

                    'month' =>
                    $upload->created_at
                        ? $upload->created_at->format('F Y')
                        : null,

                    'year' =>
                    $upload->created_at
                        ? $upload->created_at->year
                        : null,

                    'month_number' =>
                    $upload->created_at
                        ? $upload->created_at->month
                        : null,

                    'file_name' =>
                    $upload->file_name,

                    /*
                     * Count records actually transferred
                     * to the main recoveries table.
                     */
                    'total_employees' =>
                    $upload->recoveries()->count(),

                    'uploaded_by' =>
                    optional(
                        $upload->user
                    )->name,

                    'status' =>
                    $upload->status,

                    'uploaded_at' =>
                    $upload->created_at
                        ? $upload->created_at->format(
                            'Y-m-d H:i:s'
                        )
                        : null,
                ];
            });

        return response()->json([
            'status' => 200,

            'data' => $uploads,
        ]);
    }


    /**
     * =========================================================
     * LEVEL 2 - RECOVERIES OF ONE DOCUMENT
     * =========================================================
     *
     * GET /api/v1/admin/recoveries/uploads/{upload}/rows
     *
     * Example:
     *
     * GET /api/v1/admin/recoveries/uploads/1/rows
     *
     * This API is called when the user clicks a document.
     */
    public function uploadRows(
        Request $request,
        RecoveryUpload $upload
    ) {

        /*
         * Only successfully submitted documents
         * should be accessible here.
         */
        if ($upload->status !== 'success') {

            return response()->json([
                'status' => 404,

                'message' =>
                'Recovery document not found.',
            ], 404);
        }

        /*
         * Query main RECOVERIES table.
         */
        $query = Recovery::query()
            ->with('employee')
            ->where(
                'recovery_upload_id',
                $upload->id
            )
            ->latest();

        /*
         * SEARCH EMPLOYEE / RECOVERY DATA
         */
        if ($request->filled('search')) {

            $search = trim(
                $request->input('search')
            );

            $query->where(function ($q) use ($search) {

                $q->where(
                    'employee_code',
                    'like',
                    "%{$search}%"
                );

                $q->orWhere(
                    'employee_name',
                    'like',
                    "%{$search}%"
                );

                $q->orWhere(
                    'recovery_type',
                    'like',
                    "%{$search}%"
                );

                $q->orWhere(
                    'particulars',
                    'like',
                    "%{$search}%"
                );
            });
        }

        /*
         * PAGINATION
         */
        $recoveries = $query->paginate(
            (int) $request->input(
                'per_page',
                10
            )
        );

        /*
         * FORMAT RECOVERY ROWS
         */
        $recoveries
            ->getCollection()
            ->transform(
                function ($recovery) {

                    return [

                        'id' =>
                        $recovery->id,

                        'recovery_upload_id' =>
                        $recovery->recovery_upload_id,

                        'employee_id' =>
                        $recovery->employee_id,

                        'employee_code' =>
                        $recovery->employee_code,

                        'employee_name' =>
                        $recovery->employee_name,

                        'recovery_type' =>
                        ucfirst(
                            $recovery->recovery_type
                        ),

                        'particulars' =>
                        $recovery->particulars,

                        'amount' =>
                        $recovery->amount,

                        'damage_loss_date' =>
                        $recovery->damage_loss_date
                            ?->format('Y-m-d'),

                        'show_cause_issued' =>
                        $recovery->show_cause_issued,

                        'explanation_witness' =>
                        $recovery->explanation_witness,

                        'number_of_installments' =>
                        $recovery->number_of_installments,

                        'first_month_year' =>
                        $recovery->first_month_year,

                        'last_month_year' =>
                        $recovery->last_month_year,

                        'complete_recovery_date' =>
                        $recovery
                            ->complete_recovery_date
                            ?->format('Y-m-d'),

                        'remarks' =>
                        $recovery->remarks,

                        'created_at' =>
                        $recovery->created_at
                            ?->format(
                                'Y-m-d H:i:s'
                            ),
                    ];
                }
            );

        /*
         * Return document information + rows.
         */
        return response()->json([
            'status' => 200,

            'data' => [

                'upload' => [

                    'id' =>
                    $upload->id,

                    'document_id' =>
                    $upload->document_id,

                    'file_name' =>
                    $upload->file_name,

                    'month' =>
                    $upload->created_at
                        ? $upload->created_at->format(
                            'F Y'
                        )
                        : null,

                    'year' =>
                    $upload->created_at
                        ? $upload->created_at->year
                        : null,

                    'uploaded_by' =>
                    optional(
                        $upload->user
                    )->name,

                    'status' =>
                    $upload->status,

                    'total_employees' =>
                    $upload->recoveries()->count(),
                ],

                'rows' =>
                $recoveries,
            ],
        ]);
    }


    /**
     * =========================================================
     * LEVEL 3 - RECOVERY DETAILS
     * =========================================================
     *
     * GET /api/v1/admin/recoveries/{recovery}/details
     *
     * Example:
     *
     * GET /api/v1/admin/recoveries/15/details
     */
    public function details(
        Recovery $recovery
    ) {

        $recovery->load([
            'employee',
            'upload',
        ]);

        return response()->json([
            'status' => 200,

            'data' => [

                'id' =>
                $recovery->id,

                'recovery_upload_id' =>
                $recovery->recovery_upload_id,

                'document_id' =>
                optional(
                    $recovery->upload
                )->document_id,

                'employee_id' =>
                $recovery->employee_id,

                'employee_code' =>
                $recovery->employee_code,

                'employee_name' =>
                $recovery->employee_name,

                'recovery_type' =>
                ucfirst(
                    $recovery->recovery_type
                ) . ' Recovery',

                'total_amount' =>
                $recovery->amount,

                'installments' =>
                $recovery->number_of_installments,

                'damage_loss_date' =>
                $recovery
                    ->damage_loss_date
                    ?->format('Y-m-d'),

                'show_cause_issued' =>
                $recovery->show_cause_issued,

                'first_month_year' =>
                $recovery->first_month_year,

                'last_month_year' =>
                $recovery->last_month_year,

                'complete_recovery_date' =>
                $recovery
                    ->complete_recovery_date
                    ?->format('Y-m-d'),

                'explanation_witness' =>
                $recovery->explanation_witness,

                'particulars' =>
                $recovery->particulars,

                'remarks' =>
                $recovery->remarks,

                'created_at' =>
                $recovery->created_at
                    ?->format(
                        'Y-m-d H:i:s'
                    ),
            ],
        ]);
    }
}
