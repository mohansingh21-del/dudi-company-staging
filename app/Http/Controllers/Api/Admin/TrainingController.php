<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrainingRequest;
use App\Http\Requests\UpdateTrainingRequest;
use App\Http\Resources\TrainingResource;
use App\Models\Training;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainingController extends Controller
{
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $trainings = Training::with([
                'trainingType',
                'supervisor',
                'employees.designation',
            ]);

            /*
            |--------------------------------------------------------------------------
            | SEARCH
            |--------------------------------------------------------------------------
            */
            if ($request->filled('search')) {

                $search = $request->search;

                $trainings->where(function ($query) use ($search) {

                    $query->where('training_name', 'LIKE', "%{$search}%")

                        ->orWhereHas('trainingType', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        })

                        ->orWhereHas('supervisor', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('employee_code', 'LIKE', "%{$search}%");
                        });
                });
            }

            /*
            |--------------------------------------------------------------------------
            | FILTERS
            |--------------------------------------------------------------------------
            */
            if ($request->filled('training_type_id')) {
                $trainings->where('training_type_id', $request->training_type_id);
            }

            if ($request->filled('supervisor_id')) {
                $trainings->where('supervisor_id', $request->supervisor_id);
            }

            if ($request->filled('status')) {
                $trainings->where('is_active', $request->status);
            }

            // An employee's own training history.
            if ($request->filled('employee_id')) {
                $trainings->whereHas('employees', function ($q) use ($request) {
                    $q->where('employees.id', $request->employee_id);
                });
            }

            /*
            |--------------------------------------------------------------------------
            | DATE RANGE — trainings overlapping the window, not only those
            | starting inside it.
            |--------------------------------------------------------------------------
            */
            if ($request->filled('date_from')) {
                $trainings->whereDate('end_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $trainings->whereDate('start_date', '<=', $request->date_to);
            }

            $trainings = $trainings
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Training list fetched successfully',
                'data' => TrainingResource::collection($trainings),
                'pagination' => [
                    'current_page' => $trainings->currentPage(),
                    'last_page' => $trainings->lastPage(),
                    'per_page' => $trainings->perPage(),
                    'total' => $trainings->total(),
                    'from' => $trainings->firstItem(),
                    'to' => $trainings->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function store(StoreTrainingRequest $request)
    {
        DB::beginTransaction();

        try {

            $training = Training::create([
                'training_name' => $request->training_name,
                'training_type_id' => $request->training_type_id,
                'supervisor_id' => $request->supervisor_id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'is_active' => 1,
                'created_by' => $request->user() ? $request->user()->id : null,
            ]);

            $training->employees()->sync($request->employee_ids);

            DB::commit();

            $training->load(['trainingType', 'supervisor', 'employees.designation']);

            return response()->json([
                'status' => 200,
                'message' => 'Training scheduled successfully',
                'data' => new TrainingResource($training)
            ]);
        } catch (\Throwable $th) {

            DB::rollBack();

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function show(int $id)
    {
        $training = Training::with([
            'trainingType',
            'supervisor',
            'employees.designation',
        ])->find($id);

        if (!$training) {
            return response()->json([
                'status' => 404,
                'message' => 'Training not found'
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'data' => new TrainingResource($training)
        ]);
    }

    public function update(UpdateTrainingRequest $request, int $id)
    {
        $training = Training::find($id);

        if (!$training) {
            return response()->json([
                'status' => 404,
                'message' => 'Training not found'
            ], 404);
        }

        DB::beginTransaction();

        try {

            $training->update([
                'training_name' => $request->training_name,
                'training_type_id' => $request->training_type_id,
                'supervisor_id' => $request->supervisor_id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
            ]);

            // The batch is sent whole every time — sync drops anyone removed
            // from the picker and adds anyone new.
            $training->employees()->sync($request->employee_ids);

            DB::commit();

            $training->load(['trainingType', 'supervisor', 'employees.designation']);

            return response()->json([
                'status' => 200,
                'message' => 'Training updated successfully',
                'data' => new TrainingResource($training)
            ]);
        } catch (\Throwable $th) {

            DB::rollBack();

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(int $id)
    {
        $training = Training::find($id);

        if (!$training) {
            return response()->json([
                'status' => 404,
                'message' => 'Training not found'
            ], 404);
        }

        DB::beginTransaction();

        try {

            // Enrolments go with it — the FK cascades, this is explicit so the
            // intent is readable.
            $training->employees()->detach();

            $training->delete();

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'Training deleted successfully'
            ]);
        } catch (\Throwable $th) {

            DB::rollBack();

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(Request $request, int $id)
    {
        $training = Training::find($id);

        if (!$training) {
            return response()->json([
                'status' => 404,
                'message' => 'Training not found'
            ], 404);
        }

        $request->validate([
            'status' => 'nullable|in:0,1'
        ]);

        // Sending a status sets it explicitly; sending nothing flips whatever
        // is there now, so a toggle button can fire without first knowing the
        // current value. Note filled() treats an explicit 0 as present.
        $training->is_active = $request->filled('status')
            ? (int) (bool) $request->status
            : ($training->is_active ? 0 : 1);

        $training->save();

        return response()->json([
            'status' => 200,
            'message' => 'Training status updated successfully',
            'data' => [
                'id' => $training->id,
                'status' => $training->is_active,
            ]
        ]);
    }
}
