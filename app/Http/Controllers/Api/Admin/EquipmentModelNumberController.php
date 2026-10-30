<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\EquipmentModelNumber;

use App\Http\Requests\StoreEquipmentModelNumberRequest;
use App\Http\Requests\UpdateEquipmentModelNumberRequest;

use App\Http\Resources\EquipmentModelNumberResource;

class EquipmentModelNumberController extends Controller
{

    /**
     * Active model numbers for the equipment name form's dropdown.
     * Pass ?equipment_id= to list only the models of the chosen category.
     */
    public function publicIndex(Request $request)
    {
        try {
            $query = EquipmentModelNumber::where('is_active', 1)->with('equipment');

            if ($request->filled('equipment_id')) {
                $query->where('equipment_id', $request->equipment_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('model_number', 'LIKE', "%{$search}%")
                        ->orWhere('manufacturer', 'LIKE', "%{$search}%");
                });
            }

            $modelNumbers = $query->orderBy('model_number', 'asc')
                ->get()
                ->map(function ($modelNumber) {
                    return [
                        'id' => $modelNumber->id,
                        'model_number' => $modelNumber->model_number,
                        'manufacturer' => $modelNumber->manufacturer,
                        'equipment_id' => $modelNumber->equipment_id,
                        'equipment_category_name' => $modelNumber->equipment ? $modelNumber->equipment->name : null,
                        'status' => $modelNumber->is_active,
                    ];
                });

            return response()->json([
                'status' => 200,
                'message' => 'Equipment model numbers fetched successfully',
                'data' => $modelNumbers->values()->toArray()
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }



    public function index(Request $request)
    {

        try {

            $limit = $request->input('limit', 10);


            $modelNumbers = EquipmentModelNumber::with('equipment');



            // Search Model Number + Manufacturer + Category

            if ($request->filled('search')) {

                $search = $request->search;


                $modelNumbers->where(function ($query) use ($search) {

                    $query->where('model_number', 'LIKE', "%{$search}%")

                        ->orWhere('manufacturer', 'LIKE', "%{$search}%")

                        ->orWhereHas('equipment', function ($q) use ($search) {

                            $q->where('name', 'LIKE', "%{$search}%");
                        });
                });
            }



            // Filter Category

            if ($request->filled('equipment_id')) {

                $modelNumbers->where(
                    'equipment_id',
                    $request->equipment_id
                );
            }



            $modelNumbers = $modelNumbers
                ->latest()
                ->paginate($limit);



            return response()->json([

                'status' => 200,

                'message' => 'Equipment model number list fetched successfully',

                'data' => EquipmentModelNumberResource::collection(
                    $modelNumbers
                ),

                'pagination' => [

                    'current_page' => $modelNumbers->currentPage(),

                    'last_page' => $modelNumbers->lastPage(),

                    'per_page' => $modelNumbers->perPage(),

                    'total' => $modelNumbers->total(),

                    'from' => $modelNumbers->firstItem(),

                    'to' => $modelNumbers->lastItem()

                ]

            ]);
        } catch (\Throwable $th) {

            return response()->json([

                'status' => 500,

                'message' => $th->getMessage()

            ]);
        }
    }





    public function store(StoreEquipmentModelNumberRequest $request)
    {

        EquipmentModelNumber::create([

            'equipment_id' => $request->equipment_id,

            'model_number' => $request->model_number,

            'manufacturer' => $request->manufacturer,

            'is_active' => 1

        ]);



        return response()->json([

            'status' => 200,

            'message' => 'Equipment model number created successfully'

        ]);
    }





    public function show(int $id)
    {

        $modelNumber = EquipmentModelNumber::with('equipment')
            ->find($id);



        if (!$modelNumber) {

            return response()->json([

                'status' => 404,

                'message' => 'Equipment model number not found'

            ]);
        }



        return response()->json([

            'status' => 200,

            'data' => new EquipmentModelNumberResource(
                $modelNumber
            )

        ]);
    }





    public function update(
        UpdateEquipmentModelNumberRequest $request,
        int $id
    ) {

        $modelNumber = EquipmentModelNumber::find($id);



        if (!$modelNumber) {

            return response()->json([

                'status' => 404,

                'message' => 'Equipment model number not found'

            ]);
        }



        // Moving a model to another category would leave machines of the old
        // category pointing at a model that no longer belongs to it.

        if (
            (int) $request->equipment_id !== (int) $modelNumber->equipment_id
            && $modelNumber->equipmentNames()->exists()
        ) {

            return response()->json([

                'status' => 422,

                'message' => 'Category cannot be changed while equipment names use this model number'

            ], 422);
        }



        $modelNumber->update([

            'equipment_id' => $request->equipment_id,

            'model_number' => $request->model_number,

            'manufacturer' => $request->manufacturer

        ]);



        return response()->json([

            'status' => 200,

            'message' => 'Equipment model number updated successfully'

        ]);
    }





    public function destroy(int $id)
    {

        $modelNumber = EquipmentModelNumber::find($id);



        if (!$modelNumber) {

            return response()->json([

                'status' => 404,

                'message' => 'Equipment model number not found'

            ]);
        }



        if ($modelNumber->equipmentNames()->exists()) {

            return response()->json([

                'status' => 422,

                'message' => 'Equipment model number is in use by equipment names and cannot be deleted. Deactivate it instead.'

            ], 422);
        }



        $modelNumber->delete();



        return response()->json([

            'status' => 200,

            'message' => 'Equipment model number deleted successfully'

        ]);
    }





    public function toggleStatus(Request $request, int $id)
    {

        $modelNumber = EquipmentModelNumber::find($id);



        if (!$modelNumber) {

            return response()->json([

                'status' => 404,

                'message' => 'Equipment model number not found'

            ]);
        }



        $request->validate([

            'status' => 'required|in:0,1'

        ]);



        $modelNumber->is_active =
            $request->status ? 1 : 0;



        $modelNumber->save();



        return response()->json([

            'status' => 200,

            'message' => 'Equipment model number status updated successfully'

        ]);
    }
}
