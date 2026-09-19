<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Concerns\GuardsMasterDeactivation;
use App\Models\IncidentType;
use App\Http\Requests\StoreIncidentTypeRequest;
use App\Http\Requests\UpdateIncidentTypeRequest;
use App\Http\Resources\IncidentTypeResource;
use Illuminate\Http\Request;


class IncidentTypeController extends Controller
{
    use GuardsMasterDeactivation;

    public function publicIndex()
    {
        $types = IncidentType::where('is_active', 1)
            ->latest()
            ->get()
            ->map(function ($type) {
                return [
                    'id' => $type->id,
                    'incident_type' => $type->incident_type,
                    'description' => $type->description,
                    'status' => $type->is_active,
                ];
            });

        return response()->json([
            'status' => 200,
            'message' => 'Incident type list fetched successfully',
            'data' => $types
        ]);
    }
    public function index(Request $request)
    {

        $limit = $request->input('limit', 10);


        $types = IncidentType::query();



        // Search Incident Type + Description
        if ($request->filled('search')) {

            $search = $request->search;


            $types->where(function ($query) use ($search) {

                $query->where(
                    'incident_type',
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

            'message' => 'Incident type list fetched successfully',


            'data' => IncidentTypeResource::collection($types),


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





    public function store(
        StoreIncidentTypeRequest $request
    ) {


        $type = IncidentType::create([

            'incident_type' => $request->incident_type,

            'description' => $request->description

        ]);


        return response()->json([

            'status' => 200,

            'message' => 'Incident type created successfully',

            //'data' => new IncidentTypeResource($type)

        ], 200);
    }






    public function update(
        UpdateIncidentTypeRequest $request,
        $id
    ) {


        $type = IncidentType::find($id);


        if (!$type) {

            return response()->json([

                'status' => 404,

                'message' => 'Incident type not found'

            ]);
        }



        $type->update([

            'incident_type' => $request->incident_type,

            'description' => $request->description ?? $type->description,

        ]);



        return response()->json([

            'status' => 200,

            'message' => 'Incident type updated successfully'

        ]);
    }






    public function status(
        Request $request,
        $id
    ) {


        $request->validate([

            'is_active' => 'required|boolean'

        ]);



        $type = IncidentType::find($id);



        if (!$type) {

            return response()->json([

                'status' => 404,

                'message' => 'Incident type not found'

            ]);
        }



        $type->update([

            'is_active' => $request->is_active

        ]);



        return response()->json([

            'status' => 200,

            'message' => 'Incident type status updated'

        ]);
    }
    public function show(int $id)
    {
        $dept = IncidentType::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Equipment not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new IncidentTypeResource($dept)
        ]);
    }
    public function toggleStatus(Request $request, int $id)
    {


        $equipmentName = IncidentType::find($id);



        if (!$equipmentName) {

            return response()->json([

                'status' => 404,

                'message' => 'Incident Type not found'

            ]);
        }



        $request->validate([

            'status' => 'required|in:0,1'

        ]);



        if ($blocked = $this->blockDeactivation($equipmentName, $request->status)) {
            return $blocked;
        }

        $equipmentName->is_active =
            $request->status ? 1 : 0;



        $equipmentName->save();




        return response()->json([


            'status' => 200,

            'message' => 'Incident Type status updated successfully'


        ]);
    }
}
