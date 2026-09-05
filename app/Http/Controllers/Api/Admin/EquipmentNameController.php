<?php

namespace App\Http\Controllers\Api\Admin;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\EquipmentName;

use App\Http\Requests\StoreEquipmentNameRequest;
use App\Http\Requests\UpdateEquipmentNameRequest;

use App\Http\Resources\EquipmentNameResource;
use App\Http\Resources\PublicEquipmentNameResource;

class EquipmentNameController extends Controller
{


    public function index(Request $request)
    {

        try {


            $limit = $request->input('limit', 10);


            $equipmentNames = EquipmentName::with('equipment');



            // Search Equipment Name + Category

            if ($request->filled('search')) {

                $search = $request->search;


                $equipmentNames->where(function ($query) use ($search) {


                    $query->where(
                        'equipment_name',
                        'LIKE',
                        "%{$search}%"
                    )


                        ->orWhereHas('equipment', function ($q) use ($search) {


                            $q->where(
                                'name',
                                'LIKE',
                                "%{$search}%"
                            );
                        });
                });
            }



            // Filter Category

            if ($request->filled('equipment_id')) {

                $equipmentNames->where(
                    'equipment_id',
                    $request->equipment_id
                );
            }



            $equipmentNames = $equipmentNames
                ->latest()
                ->paginate($limit);



            return response()->json([


                'status' => 200,

                'message' => 'Equipment name list fetched successfully',


                'data' => EquipmentNameResource::collection(
                    $equipmentNames
                ),


                'pagination' => [

                    'current_page' => $equipmentNames->currentPage(),

                    'last_page' => $equipmentNames->lastPage(),

                    'per_page' => $equipmentNames->perPage(),

                    'total' => $equipmentNames->total(),

                    'from' => $equipmentNames->firstItem(),

                    'to' => $equipmentNames->lastItem()

                ]


            ]);
        } catch (\Throwable $th) {


            return response()->json([

                'status' => 500,

                'message' => $th->getMessage()

            ]);
        }
    }





    public function store(StoreEquipmentNameRequest $request)
    {


        EquipmentName::create([


            'equipment_id' => $request->equipment_id,


            'equipment_name' => $request->equipment_name,


            'is_active' => 1


        ]);



        return response()->json([


            'status' => 200,

            'message' => 'Equipment name created successfully'


        ]);
    }







    public function show(int $id)
    {


        $equipmentName = EquipmentName::with('equipment')
            ->find($id);



        if (!$equipmentName) {

            return response()->json([

                'status' => 404,

                'message' => 'Equipment name not found'

            ]);
        }



        return response()->json([


            'status' => 200,


            'data' => new EquipmentNameResource(
                $equipmentName
            )


        ]);
    }







    public function update(
        UpdateEquipmentNameRequest $request,
        int $id
    ) {


        $equipmentName = EquipmentName::find($id);



        if (!$equipmentName) {

            return response()->json([

                'status' => 404,

                'message' => 'Equipment name not found'

            ]);
        }



        $equipmentName->update([


            'equipment_id' => $request->equipment_id,


            'equipment_name' => $request->equipment_name


        ]);




        return response()->json([


            'status' => 200,


            'message' => 'Equipment name updated successfully'


        ]);
    }







    public function destroy(int $id)
    {


        $equipmentName = EquipmentName::find($id);



        if (!$equipmentName) {

            return response()->json([

                'status' => 404,

                'message' => 'Equipment name not found'

            ]);
        }



        $equipmentName->delete();



        return response()->json([


            'status' => 200,


            'message' => 'Equipment name deleted successfully'


        ]);
    }








    public function toggleStatus(Request $request, int $id)
    {


        $equipmentName = EquipmentName::find($id);



        if (!$equipmentName) {

            return response()->json([

                'status' => 404,

                'message' => 'Equipment name not found'

            ]);
        }



        $request->validate([

            'status' => 'required|in:0,1'

        ]);



        $equipmentName->is_active =
            $request->status ? 1 : 0;



        $equipmentName->save();




        return response()->json([


            'status' => 200,

            'message' => 'Equipment name status updated successfully'


        ]);
    }

    public function getPublicEquipmentNames(Request $request, $id)
    {
        try {
            $query = EquipmentName::where('is_active', 1)
                ->where('equipment_id', $id)
                ->with('equipment');

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('equipment_name', 'LIKE', "%{$search}%");
                });
            }

            $limit = $request->input('limit', null);

            if ($limit) {
                $equipmentNames = $query->latest()->paginate($limit);
                return response()->json([
                    'status' => 200,
                    'message' => 'Equipment names fetched successfully',
                    'data' => PublicEquipmentNameResource::collection($equipmentNames),
                    'pagination' => [
                        'current_page' => $equipmentNames->currentPage(),
                        'last_page' => $equipmentNames->lastPage(),
                        'per_page' => $equipmentNames->perPage(),
                        'total' => $equipmentNames->total(),
                        'from' => $equipmentNames->firstItem(),
                        'to' => $equipmentNames->lastItem(),
                    ]
                ]);
            }

            $equipmentNames = $query->latest()->get();

            return response()->json([
                'status' => 200,
                'message' => 'Equipment names fetched successfully',
                'data' => PublicEquipmentNameResource::collection($equipmentNames)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get all active machines across every category.
     * Lightweight listing meant for dropdowns (no availability lookups).
     */
    public function getActiveMachines(Request $request)
    {
        try {
            $query = EquipmentName::where('is_active', 1)->with('equipment');

            // Optional Category Filter
            if ($request->filled('equipment_id')) {
                $query->where('equipment_id', $request->equipment_id);
            }

            // Optional Search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('equipment_name', 'LIKE', "%{$search}%")
                        ->orWhere('chassis_number', 'LIKE', "%{$search}%");
                });
            }

            // Only machines a telematics feed reports on. For the fleet
            // dashboard's dumper filter: without it the dropdown also lists
            // machines with no chassis - a dozer, a pump - and picking one
            // returns an empty dashboard that reads as a fault rather than as
            // "this machine has no telemetry".
            if ($request->boolean('has_telematics')) {
                $query->whereNotNull('chassis_number');
            }

            $query->orderBy('equipment_name', 'asc');

            $format = function ($machine) {
                return [
                    'id' => $machine->id,
                    'equipment_name' => $machine->equipment_name,

                    // Null on machines no telematics feed covers. Also what the
                    // fleet dashboard falls back to when equipment_name is
                    // still the raw chassis.
                    'chassis_number' => $machine->chassis_number,

                    'equipment_id' => $machine->equipment_id,
                    'equipment_category_name' => $machine->equipment ? $machine->equipment->name : null,
                    'is_active' => $machine->is_active,
                ];
            };

            if ($request->filled('limit')) {
                $machines = $query->paginate($request->limit);

                return response()->json([
                    'status' => 200,
                    'message' => 'Active machines fetched successfully',
                    'data' => collect($machines->items())->map($format)->values()->toArray(),
                    'pagination' => [
                        'current_page' => $machines->currentPage(),
                        'last_page' => $machines->lastPage(),
                        'per_page' => $machines->perPage(),
                        'total' => $machines->total(),
                        'from' => $machines->firstItem(),
                        'to' => $machines->lastItem(),
                    ]
                ]);
            }

            $machines = $query->get();

            return response()->json([
                'status' => 200,
                'message' => 'Active machines fetched successfully',
                'data' => $machines->map($format)->values()->toArray()
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
