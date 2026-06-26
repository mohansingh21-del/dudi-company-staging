<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BreakdownType;
use App\Http\Requests\StoreBreakdownTypeRequest;
use App\Http\Requests\UpdateBreakdownTypeRequest;
use App\Http\Resources\BreakdownTypeResource;
use Illuminate\Http\Request;

class BreakdownTypeController extends Controller
{
    public function publicIndex()
    {
        $types = BreakdownType::where('is_active', 1)
            ->latest()
            ->get()
            ->map(function ($type) {
                return [
                    'id' => $type->id,
                    'breakdown_type' => $type->incident_type,
                    'description' => $type->description,
                    'status' => $type->is_active,
                ];
            });

        return response()->json([
            'status' => 200,
            'message' => 'Breakdown type list fetched successfully',
            'data' => $types
        ]);
    }

    public function index(Request $request)
    {
        $limit = $request->input('limit', 10);

        $types = BreakdownType::query();

        // Search Breakdown Type + Description
        if ($request->filled('search')) {

            $search = $request->search;

            $types->where(function ($query) use ($search) {

                $query->where(
                    'breakdown_type',
                    'LIKE',
                    "%{$search}%"
                )
                    ->orWhere(
                        'description',
                        'LIKE',
                        "%{$search}%"
                    );
            });
        }

        $types = $types
            ->latest()
            ->paginate($limit);

        return response()->json([

            'status' => 200,

            'message' => 'Breakdown type list fetched successfully',

            'data' => BreakdownTypeResource::collection($types),

            'pagination' => [

                'current_page' => $types->currentPage(),

                'last_page' => $types->lastPage(),

                'per_page' => $types->perPage(),

                'total' => $types->total(),

                'from' => $types->firstItem(),

                'to' => $types->lastItem()

            ]

        ]);
    }

    public function store(StoreBreakdownTypeRequest $request)
    {
        $type = BreakdownType::create([

            'breakdown_type' => $request->breakdown_type,

            'description' => $request->description

        ]);

        return response()->json([

            'status' => 200,

            'message' => 'Breakdown type created successfully',

            //'data' => new BreakdownTypeResource($type)

        ], 200);
    }

    public function show(int $id)
    {
        $type = BreakdownType::find($id);

        if (!$type) {

            return response()->json([

                'status' => 404,

                'message' => 'Breakdown type not found'

            ]);
        }

        return response()->json([

            'status' => 200,

            'data' => new BreakdownTypeResource($type)

        ]);
    }

    public function update(
        UpdateBreakdownTypeRequest $request,
        $id
    ) {

        $type = BreakdownType::find($id);

        if (!$type) {

            return response()->json([

                'status' => 404,

                'message' => 'Breakdown type not found'

            ]);
        }

        $type->update([

            'breakdown_type' => $request->breakdown_type,

            'description' => $request->description ?? $type->description,


        ]);

        return response()->json([

            'status' => 200,

            'message' => 'Breakdown type updated successfully'

        ]);
    }

    public function destroy($id)
    {
        $type = BreakdownType::find($id);

        if (!$type) {

            return response()->json([

                'status' => 404,

                'message' => 'Breakdown type not found'

            ]);
        }

        $type->delete();

        return response()->json([

            'status' => 200,

            'message' => 'Breakdown type deleted successfully'

        ]);
    }

    public function status(
        Request $request,
        $id
    ) {

        $request->validate([

            'is_active' => 'required|boolean'

        ]);

        $type = BreakdownType::find($id);

        if (!$type) {

            return response()->json([

                'status' => 404,

                'message' => 'Breakdown type not found'

            ]);
        }

        $type->update([

            'is_active' => $request->is_active

        ]);

        return response()->json([

            'status' => 200,

            'message' => 'Breakdown type status updated'

        ]);
    }

    public function toggleStatus(Request $request, int $id)
    {
        $breakdownType = BreakdownType::find($id);

        if (!$breakdownType) {

            return response()->json([

                'status' => 404,

                'message' => 'Breakdown type not found'

            ]);
        }

        $request->validate([

            'status' => 'required|in:0,1'

        ]);

        $breakdownType->is_active =
            $request->status ? 1 : 0;

        $breakdownType->save();

        return response()->json([

            'status' => 200,

            'message' => 'Breakdown type status updated successfully'

        ]);
    }
}
