<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleTypeController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);

            $query = VehicleType::query();

            if ($search) {
                $query->where('name', 'like', '%' . $search . '%');
            }

            if ($limit) {
                $vehicleTypes = $query->orderBy('created_at', 'DESC')->paginate($limit, ['*'], 'page', $page);
                $vehicleTypes->getCollection()->transform(function ($vehicleType) {
                    return [
                        'id' => $vehicleType->id,
                        'name' => $vehicleType->name,
                        'description' => $vehicleType->description,
                        'is_active' => $vehicleType->is_active
                    ];
                });

                $response = [
                    'data' => $vehicleTypes->items(),
                    'pagination' => [
                        'total' => $vehicleTypes->total(),
                        'current_page' => $vehicleTypes->currentPage(),
                        'per_page' => $vehicleTypes->perPage(),
                        'last_page' => $vehicleTypes->lastPage(),
                        'from' => $vehicleTypes->firstItem(),
                        'to' => $vehicleTypes->lastItem(),
                        'next_page_url' => $vehicleTypes->nextPageUrl(),
                        'previous_page_url' => $vehicleTypes->previousPageUrl(),
                    ]
                ];
            } else {
                $vehicleTypes = $query->orderBy('created_at', 'DESC')->get();
                $response = [
                    'data' => $vehicleTypes,
                    'pagination' => [
                        'total' => $vehicleTypes->count(),
                        'current_page' => 1,
                        'per_page' => $vehicleTypes->count(),
                        'last_page' => 1,
                        'from' => $vehicleTypes->isEmpty() ? 0 : 1,
                        'to' => $vehicleTypes->count(),
                        'next_page_url' => null,
                        'previous_page_url' => null,
                    ]
                ];
            }

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle types fetched successfully',
                'data' => $response['data'],
                'pagination' => $response['pagination'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch vehicle types',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $vehicleType = VehicleType::findOrFail($id);
            return response()->json([
                'status' => 200,
                'message' => 'Vehicle type fetched successfully',
                'data' => $vehicleType,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Vehicle type not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch vehicle type',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $id = $request->input('id');

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:vehicle_types,name' . ($id ? ',' . $id : ''),
                'description' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $vehicleType = $id ? VehicleType::findOrFail($id) : new VehicleType();
            $vehicleType->name = $request->input('name');
            if ($request->has('description')) {
                $vehicleType->description = $request->input('description');
            }
            if (!$id) {
                $vehicleType->is_active = 1;
            }
            $vehicleType->save();

            $message = $id ? 'Vehicle type updated successfully' : 'Vehicle type created successfully';

            return response()->json([
                'status' => $id ? 200 : 201,
                'message' => $message,
                'data' => $vehicleType,
            ], $id ? 200 : 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to save vehicle type',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function toggleStatus($id)
    {
        try {
            $vehicleType = VehicleType::findOrFail($id);
            $vehicleType->is_active = $vehicleType->is_active ? 0 : 1;
            $vehicleType->save();

            $message = $vehicleType->is_active ? 'Vehicle type enabled successfully' : 'Vehicle type disabled successfully';

            return response()->json([
                'status' => 200,
                'message' => $message,
                'data' => $vehicleType,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Vehicle type not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update vehicle type status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $vehicleType = VehicleType::findOrFail($id);
            $vehicleType->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle type deleted successfully',
                'data' => [],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Vehicle type not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to delete vehicle type',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
