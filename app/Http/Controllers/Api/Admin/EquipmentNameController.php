<?php

namespace App\Http\Controllers\Api\Admin;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\EquipmentName;

use App\Http\Requests\StoreEquipmentNameRequest;
use App\Http\Requests\UpdateEquipmentNameRequest;

use App\Http\Resources\EquipmentNameResource;

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
}
