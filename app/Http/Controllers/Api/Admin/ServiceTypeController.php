<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServiceTypeController extends Controller
{
    /**
     * Display a listing of service types.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);
            $page = $request->input('page', 1);
            $search = $request->input('search');
            $status = $request->input('status'); // 1 = active, 0 = inactive
            $all = $request->input('all', 0); // 1 = returns all as a simple list for dropdowns

            $query = ServiceType::query();

            if ($search) {
                $query->where('name', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            }

            if ($status !== null) {
                $query->where('is_active', $status);
            }

            // For populating select dropdowns cleanly
            if ($all == 1) {
                $serviceTypes = $query->where('is_active', true)->orderBy('name', 'ASC')->get(['id', 'name', 'description']);
                return response()->json([
                    'status' => 200,
                    'message' => 'Service types list retrieved successfully',
                    'data' => $serviceTypes
                ]);
            }

            if ($limit) {
                $serviceTypes = $query->orderBy('created_at', 'DESC')->paginate($limit, ['*'], 'page', $page);
                $serviceTypes->getCollection()->transform(function ($serviceType) {
                    return [
                        'id' => $serviceType->id,
                        'name' => $serviceType->name,
                        'description' => $serviceType->description,
                        'is_active' => $serviceType->is_active
                    ];
                });
                $response = [
                    'data' => $serviceTypes->items(),
                    'pagination' => [
                        'total' => $serviceTypes->total(),
                        'current_page' => $serviceTypes->currentPage(),
                        'per_page' => $serviceTypes->perPage(),
                        'last_page' => $serviceTypes->lastPage(),
                        'from' => $serviceTypes->firstItem(),
                        'to' => $serviceTypes->lastItem(),
                        'next_page_url' => $serviceTypes->nextPageUrl(),
                        'previous_page_url' => $serviceTypes->previousPageUrl(),
                    ]
                ];
            } else {
                $serviceTypes = $query->orderBy('created_at', 'DESC')->get();
                $serviceTypes->transform(function ($serviceType) {
                    return [
                        'id' => $serviceType->id,
                        'name' => $serviceType->name,
                        'description' => $serviceType->description,
                        'is_active' => $serviceType->is_active
                    ];
                });

                $response = [
                    'data' => $serviceTypes,
                    'pagination' => [
                        'total' => $serviceTypes->count(),
                        'current_page' => 1,
                        'per_page' => $serviceTypes->count(),
                        'last_page' => 1,
                        'from' => $serviceTypes->isEmpty() ? 0 : 1,
                        'to' => $serviceTypes->count(),
                        'next_page_url' => null,
                        'previous_page_url' => null,
                    ]
                ];
            }
            // Paginated results for the admin grid panel

            return response()->json([
                'status' => 200,
                'message' => 'Service types retrieved successfully',
                'data' => $response['data'],
                'pagination' => $response['pagination']
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to retrieve service types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created service type in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $id = $request->input('id');

            $validator = Validator::make($request->all(), [
                'id' => 'nullable|integer|exists:service_types,id',
                'name' => 'required|string|max:255|unique:service_types,name' . ($id ? ',' . $id : ''),
                'description' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($id) {
                $serviceType = ServiceType::findOrFail($id);
                $serviceType->update([
                    'name' => $request->input('name'),
                    'description' => $request->input('description'),
                ]);

                return response()->json([
                    'status' => 200,
                    'message' => 'Service type updated successfully',
                    'data' => $serviceType
                ], 200);
            } else {
                $serviceType = ServiceType::create([
                    'name' => $request->input('name'),
                    'description' => $request->input('description'),
                    'is_active' => true
                ]);

                return response()->json([
                    'status' => 201,
                    'message' => 'Service type created successfully',
                    'data' => $serviceType
                ], 201);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to save service type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified service type.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $serviceType = ServiceType::findOrFail($id);

            return response()->json([
                'status' => 200,
                'message' => 'Service type fetched successfully',
                'data' => $serviceType
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Service type not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified service type in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $request->merge(['id' => $id]);
        return $this->store($request);
    }

    /**
     * Toggle the status of the service type.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleStatus($id)
    {
        try {
            $serviceType = ServiceType::findOrFail($id);

            $serviceType->update([
                'is_active' => !$serviceType->is_active
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Service type status toggled successfully',
                'data' => $serviceType
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to toggle status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified service type from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $serviceType = ServiceType::findOrFail($id);
            $serviceType->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Service type deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to delete service type',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
