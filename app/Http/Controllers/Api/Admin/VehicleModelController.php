<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\VehicleModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleModelController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);

            $query = VehicleModel::with(['vehicleType', 'vehicleManufacturer']);

            if ($search) {
                $query->where('name', 'like', '%' . $search . '%')
                    ->orWhereHas('vehicleType', function ($q) use ($search) {
                        $q->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('vehicleManufacturer', function ($q) use ($search) {
                        $q->where('name', 'like', '%' . $search . '%');
                    });
            }

            if ($limit) {
                $vehicleModels = $query->orderBy('created_at', 'DESC')->paginate($limit, ['*'], 'page', $page);
                $vehicleModels->getCollection()->transform(function ($vehicleModel) {
                    return [
                        'id' => $vehicleModel->id,
                        'name' => $vehicleModel->name,
                        'is_active' => $vehicleModel->is_active,
                        'vehicle_type' => $vehicleModel->vehicleType,
                        'vehicle_manufacturer' => $vehicleModel->vehicleManufacturer,
                    ];
                });

                $response = [
                    'data' => $vehicleModels->items(),
                    'pagination' => [
                        'total' => $vehicleModels->total(),
                        'current_page' => $vehicleModels->currentPage(),
                        'per_page' => $vehicleModels->perPage(),
                        'last_page' => $vehicleModels->lastPage(),
                        'from' => $vehicleModels->firstItem(),
                        'to' => $vehicleModels->lastItem(),
                        'next_page_url' => $vehicleModels->nextPageUrl(),
                        'previous_page_url' => $vehicleModels->previousPageUrl(),
                    ]
                ];
            } else {
                $vehicleModels = $query->orderBy('created_at', 'DESC')->get();
                $response = [
                    'data' => $vehicleModels,
                    'pagination' => [
                        'total' => $vehicleModels->count(),
                        'current_page' => 1,
                        'per_page' => $vehicleModels->count(),
                        'last_page' => 1,
                        'from' => $vehicleModels->isEmpty() ? 0 : 1,
                        'to' => $vehicleModels->count(),
                        'next_page_url' => null,
                        'previous_page_url' => null,
                    ]
                ];
            }

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle models fetched successfully',
                'data' => $response['data'],
                'pagination' => $response['pagination'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch vehicle models',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $id = $request->input('id', null);
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:vehicle_models,name' . ($id ? ',' . $id : ''),
                'vehicle_type_id' => 'required|exists:vehicle_types,id',
                'vehicle_manufacturer_id' => 'required|exists:vehicle_manufacturers,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 400,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 400);
            }

            $vehicleModel = $id ? VehicleModel::findOrFail($id) : new VehicleModel();
            $vehicleModel->fill($validator->validated());
            $vehicleModel->save();

            return response()->json([
                'status' => $id ? 200 : 201,
                'message' => $id ? 'Vehicle model updated successfully' : 'Vehicle model created successfully',
                'data' => $vehicleModel,
            ], $id ? 200 : 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to ' . ($id ? 'update' : 'create') . ' vehicle model',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $vehicleModel = VehicleModel::with(['vehicleType', 'vehicleManufacturer'])->findOrFail($id);

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle model fetched successfully',
                'data' => [
                    'id' => $vehicleModel->id,
                    'name' => $vehicleModel->name,
                    'is_active' => $vehicleModel->is_active,
                    'vehicle_type_id' => $vehicleModel->vehicle_type_id,
                    'vehicle_type' => $vehicleModel->vehicleType,
                    'vehicle_manufacturer_id' => $vehicleModel->vehicle_manufacturer_id,
                    'vehicle_manufacturer' => $vehicleModel->vehicleManufacturer,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch vehicle model',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function toggleStatus($id)
    {
        try {
            $vehicleModel = VehicleModel::findOrFail($id);
            $vehicleModel->is_active = $vehicleModel->is_active ? 0 : 1;
            $vehicleModel->save();

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle model ' . ($vehicleModel->is_active ? 'enabled' : 'disabled') . ' successfully',
                'data' => $vehicleModel,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to ' . ($vehicleModel->is_active ? 'enable' : 'disable') . ' vehicle model',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
