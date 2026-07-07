<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ShiftAlreadyClosedException;
use App\Exceptions\ShiftClosureValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseShiftRequest;
use App\Models\ShiftPlan;
use App\Services\ShiftClosureService;

class ShiftClosureController extends Controller
{
    /**
     * @var ShiftClosureService
     */
    protected $service;

    public function __construct(ShiftClosureService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /shift-plans/{shift}/closure-summary
     *
     * Consolidated review screen data — read-only aggregation, no side effects.
     *
     * @param  ShiftPlan  $shift
     * @return \Illuminate\Http\JsonResponse
     */
    public function summary(ShiftPlan $shift)
    {
        try {
            // BR-SHFT-029: If shift is already completed, still allow viewing frozen snapshot
            if ($shift->status === 'completed' && $shift->shift_summary_snapshot) {
                return response()->json([
                    'status'  => 200,
                    'message' => 'Shift closure summary retrieved successfully.',
                    'data'    => json_decode($shift->shift_summary_snapshot, true),
                ], 200);
            }

            // Must be published or in_progress to view closure summary
            if (!in_array($shift->status, ['published', 'in_progress'])) {
                return response()->json([
                    'status'  => 422,
                    'message' => 'Shift Must Be Published Or In Progress To View Closure Summary.',
                    'data'    => null,
                ], 422);
            }

            $data = $this->service->getClosureSummary($shift);

            return response()->json([
                'status'  => 200,
                'message' => 'Shift closure summary retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * POST /shift-plans/{shift}/close
     *
     * Finalizes the shift closure (BR-SHFT-022).
     * Merges has_active_breakdown flag into the request before validation
     * triggers (PHP 7.4 compatible).
     *
     * @param  CloseShiftRequest  $request
     * @param  ShiftPlan          $shift
     * @return \Illuminate\Http\JsonResponse
     */
    public function close(CloseShiftRequest $request, ShiftPlan $shift)
    {
        try {
            $updated = $this->service->closeShift(
                $shift,
                $request->validated(),
                $request->user()
            );

            $closedByName = null;
            $closedByUser = $updated->closedByUser;
            if ($closedByUser) {
                $emp = $closedByUser->employee;
                $closedByName = $emp ? $emp->name : $closedByUser->email;
            }

            return response()->json([
                'status'  => 200,
                'message' => 'Shift Closed Successfully.',
                'data'    => [
                    'shift_id'     => $updated->id,
                    'reference_no' => $updated->reference_no,
                    'status'       => $updated->status,
                    'closed_by'    => $closedByName,
                    'closure_date' => $updated->closure_date,
                    'closure_time' => $updated->closure_time,
                ],
            ], 200);
        } catch (ShiftAlreadyClosedException $e) {
            return response()->json([
                'status'  => 409,
                'message' => $e->getMessage(),
                'data'    => [],
            ], 409);
        } catch (ShiftClosureValidationException $e) {
            return response()->json([
                'status'  => 422,
                'message' => $e->getMessage(),
                'data'    => [],
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }
}
