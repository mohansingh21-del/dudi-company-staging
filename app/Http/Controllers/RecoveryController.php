<?php

namespace App\Http\Controllers;

use App\Models\Recovery;
use App\Models\RecoveryUpload;
use Illuminate\Http\Request;

class RecoveryController extends Controller
{
    /**
     * Final Recovery Register
     *
     * GET /api/recoveries
     * GET /api/recoveries?year=2026
     * GET /api/recoveries?year=2026&month=3
     * GET /api/recoveries?upload_id=1
     */
    public function index(Request $request)
    {
        $query = RecoveryUpload::query()
            ->with('user')
            ->where('status', 'success');

        // Year filter
        if ($request->filled('year')) {
            $query->whereYear(
                'created_at',
                (int) $request->input('year')
            );
        }

        // Month filter
        if ($request->filled('month')) {
            $query->whereMonth(
                'created_at',
                (int) $request->input('month')
            );
        }

        // Specific uploaded file
        if ($request->filled('upload_id')) {
            $query->where(
                'id',
                (int) $request->input('upload_id')
            );
        }

        // Search by filename
        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where('file_name', 'like', "%{$search}%");
        }

        $uploads = $query
            ->latest('created_at')
            ->paginate(
                (int) $request->input('per_page', 10)


            );

        $uploads->getCollection()->transform(function ($upload) {

            return [
                'id' => $upload->id,

                'document_id' => $upload->document_id,

                'month' => $upload->created_at
                    ? $upload->created_at->format('F Y')
                    : null,

                'year' => $upload->created_at
                    ? $upload->created_at->year
                    : null,

                'month_number' => $upload->created_at
                    ? $upload->created_at->month
                    : null,

                'file_name' => $upload->file_name,

                'total_employees' => $upload->rows()->count(),

                'uploaded_by' => optional($upload->user)->name,

                'status' => $upload->status,
            ];
        });

        return response()->json([
            'status' => 200,
            'data' => $uploads,
        ]);
    }


    /**
     * Final recovery details.
     *
     * GET /api/recoveries/{recovery}/details
     */
    public function details(Recovery $recovery)
    {
        $recovery->load('employee');

        return response()->json([
            'status' => 200,

            'data' => [
                'id' => $recovery->id,

                'employee_id' => $recovery->employee_id,

                'employee_code' => $recovery->employee_code,

                'employee_name' => $recovery->employee_name,

                'recovery_type' =>
                ucfirst($recovery->recovery_type) . ' Recovery',

                'total_amount' =>
                $recovery->amount,

                'installments' =>
                $recovery->number_of_installments,

                'damage_loss_date' =>
                $recovery->damage_loss_date?->format('Y-m-d'),

                'show_cause_issued' =>
                $recovery->show_cause_issued,

                'first_month_year' =>
                $recovery->first_month_year,

                'last_month_year' =>
                $recovery->last_month_year,

                'complete_recovery_date' =>
                $recovery->complete_recovery_date?->format('Y-m-d'),

                'explanation_witness' =>
                $recovery->explanation_witness,

                'particulars' =>
                $recovery->particulars,

                'remarks' =>
                $recovery->remarks,
            ]
        ]);
    }
}
