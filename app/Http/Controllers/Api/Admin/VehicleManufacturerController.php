<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\VehicleManufacturer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleManufacturerController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);

            $query = VehicleManufacturer::query();

            if ($search) {
                $query->where('name', 'like', '%' . $search . '%');
            }

            if ($limit) {
                $vehicleManufacturers = $query->orderBy('created_at', 'DESC')->paginate($limit, ['*'], 'page', $page);
                $vehicleManufacturers->getCollection()->transform(function ($vehicleManufacturer) {
                    return [
                        'id' => $vehicleManufacturer->id,
                        'name' => $vehicleManufacturer->name,
                        'is_active' => $vehicleManufacturer->is_active
                    ];
                });

                $response = [
                    'data' => $vehicleManufacturers->items(),
                    'pagination' => [
                        'total' => $vehicleManufacturers->total(),
                        'current_page' => $vehicleManufacturers->currentPage(),
                        'per_page' => $vehicleManufacturers->perPage(),
                        'last_page' => $vehicleManufacturers->lastPage(),
                        'from' => $vehicleManufacturers->firstItem(),
                        'to' => $vehicleManufacturers->lastItem(),
                        'next_page_url' => $vehicleManufacturers->nextPageUrl(),
                        'previous_page_url' => $vehicleManufacturers->previousPageUrl(),
                    ]
                ];
            } else {
                $vehicleManufacturers = $query->orderBy('created_at', 'DESC')->get();
                $response = [
                    'data' => $vehicleManufacturers,
                    'pagination' => [
                        'total' => $vehicleManufacturers->count(),
                        'current_page' => 1,
                        'per_page' => $vehicleManufacturers->count(),
                        'last_page' => 1,
                        'from' => $vehicleManufacturers->isEmpty() ? 0 : 1,
                        'to' => $vehicleManufacturers->count(),
                        'next_page_url' => null,
                        'previous_page_url' => null,
                    ]
                ];
            }

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle manufacturers fetched successfully',
                'data' => $response['data'],
                'pagination' => $response['pagination'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch vehicle manufacturers',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $vehicleManufacturer = VehicleManufacturer::findOrFail($id);
            return response()->json([
                'status' => 200,
                'message' => 'Vehicle manufacturer fetched successfully',
                'data' => $vehicleManufacturer,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Vehicle manufacturer not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch vehicle manufacturer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $id = $request->input('id');

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:vehicle_manufacturers,name' . ($id ? ',' . $id : ''),
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $vehicleManufacturer = $id ? VehicleManufacturer::findOrFail($id) : new VehicleManufacturer();
            $vehicleManufacturer->name = $request->input('name');
            if (!$id) {
                $vehicleManufacturer->is_active = 1;
            }
            $vehicleManufacturer->save();

            $message = $id ? 'Vehicle manufacturer updated successfully' : 'Vehicle manufacturer created successfully';

            return response()->json([
                'status' => $id ? 200 : 201,
                'message' => $message,
                'data' => $vehicleManufacturer,
            ], $id ? 200 : 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to save vehicle manufacturer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function toggleStatus($id)
    {
        try {
            $vehicleManufacturer = VehicleManufacturer::findOrFail($id);
            $vehicleManufacturer->is_active = $vehicleManufacturer->is_active ? 0 : 1;
            $vehicleManufacturer->save();

            $message = $vehicleManufacturer->is_active ? 'Vehicle manufacturer enabled successfully' : 'Vehicle manufacturer disabled successfully';

            return response()->json([
                'status' => 200,
                'message' => $message,
                'data' => $vehicleManufacturer,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Vehicle manufacturer not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update vehicle manufacturer status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $vehicleManufacturer = VehicleManufacturer::findOrFail($id);
            $vehicleManufacturer->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle manufacturer deleted successfully',
                'data' => [],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Vehicle manufacturer not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to delete vehicle manufacturer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
