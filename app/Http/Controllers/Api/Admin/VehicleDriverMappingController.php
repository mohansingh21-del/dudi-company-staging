<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\Employee;
use App\Models\VehicleDriverMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class VehicleDriverMappingController extends Controller
{
    /**
     * Display a listing of vehicle-driver mappings.
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
            $status = $request->input('status'); // active, inactive

            $query = VehicleDriverMapping::with([
                'vehicle:id,vehicle_number,owner_name,is_active',
                'driver:id,name,email,phone,profle_image,is_active'
            ]);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('vehicle', function ($vq) use ($search) {
                        $vq->where('vehicle_number', 'like', '%' . $search . '%');
                    })->orWhereHas('driver', function ($dq) use ($search) {
                        $dq->where('name', 'like', '%' . $search . '%')
                          ->orWhere('phone', 'like', '%' . $search . '%');
                    });
                });
            }

            if ($status) {
                $statusVal = ($status === 'active' || $status == 1) ? 1 : 0;
                $query->where('status', $statusVal);
            }

            $mappings = $query->orderBy('created_at', 'DESC')->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle-Driver mappings retrieved successfully',
                'data' => $mappings->items(),
                'pagination' => [
                    'total' => $mappings->total(),
                    'current_page' => $mappings->currentPage(),
                    'per_page' => $mappings->perPage(),
                    'last_page' => $mappings->lastPage(),
                    'from' => $mappings->firstItem(),
                    'to' => $mappings->lastItem(),
                    'next_page_url' => $mappings->nextPageUrl(),
                    'previous_page_url' => $mappings->previousPageUrl(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to retrieve mappings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new vehicle-driver mapping (Create Assignment).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'vehicle_id' => 'required|integer|exists:vehicles,id',
                'driver_id' => 'required|integer|exists:employees,id',
                'assignment_date' => 'required|date',
                'shift' => 'required|string|max:255', // e.g. Full Day, Day, Night
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $vehicleId = $request->input('vehicle_id');
            $driverId = $request->input('driver_id');
            $assignmentDate = $request->input('assignment_date');
            $shift = $request->input('shift');

            // Verify if the employee actually has the 'driver' designation/role
            $driver = Employee::where('id', $driverId)
                ->whereHas('designation', function ($q) {
                    $q->where('slug', 'driver');
                })->first();

            if (!$driver) {
                return response()->json([
                    'status' => 422,
                    'message' => 'The selected employee is not a registered Driver.'
                ], 422);
            }

            // Ensure the vehicle is active
            $vehicle = Vehicle::findOrFail($vehicleId);
            if (!$vehicle->is_active) {
                return response()->json([
                    'status' => 422,
                    'message' => 'The selected vehicle is currently inactive and cannot be assigned.'
                ], 422);
            }

            // Database Transaction to keep sync atomic and clean
            $mapping = DB::transaction(function () use ($vehicleId, $driverId, $assignmentDate, $shift) {
                // 1. Deactivate any previous active driver assignments for the same vehicle on this shift/date
                VehicleDriverMapping::where('vehicle_id', $vehicleId)
                    ->where('assignment_date', $assignmentDate)
                    ->where('shift', $shift)
                    ->where('status', 1)
                    ->update(['status' => 0]);

                // 2. Deactivate any previous active assignments of this driver to other vehicles on this shift/date
                VehicleDriverMapping::where('driver_id', $driverId)
                    ->where('assignment_date', $assignmentDate)
                    ->where('shift', $shift)
                    ->where('status', 1)
                    ->update(['status' => 0]);

                // 3. Create the new active mapping
                return VehicleDriverMapping::create([
                    'vehicle_id' => $vehicleId,
                    'driver_id' => $driverId,
                    'assignment_date' => $assignmentDate,
                    'shift' => $shift,
                    'status' => 1
                ]);
            });

            return response()->json([
                'status' => 201,
                'message' => 'Driver assigned to vehicle successfully',
                'data' => $mapping->load([
                    'vehicle:id,vehicle_number,owner_name',
                    'driver:id,name,email,phone,profle_image'
                ])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to assign driver',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unlink/Deactivate a vehicle-driver mapping.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function unlink($id)
    {
        try {
            $mapping = VehicleDriverMapping::findOrFail($id);
            
            $mapping->update([
                'status' => 'inactive'
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Driver unlinked from vehicle successfully',
                'data' => $mapping->load([
                    'vehicle:id,vehicle_number,owner_name',
                    'driver:id,name,email,phone,profle_image'
                ])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to unlink driver from vehicle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active vehicles list for dropdown mapping selector.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAvailableVehicles(Request $request)
    {
        try {
            $search = $request->input('search');
            
            $query = Vehicle::where('is_active', 1);

            if ($search) {
                $query->where('vehicle_number', 'like', '%' . $search . '%');
            }

            $vehicles = $query->orderBy('vehicle_number', 'ASC')->get(['id', 'vehicle_number', 'owner_name']);

            return response()->json([
                'status' => 200,
                'message' => 'Active vehicles retrieved successfully',
                'data' => $vehicles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to retrieve available vehicles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active drivers list for dropdown mapping selector.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAvailableDrivers(Request $request)
    {
        try {
            $search = $request->input('search');

            $query = Employee::where('is_active', 1)
                ->whereHas('designation', function ($q) {
                    $q->where('slug', 'driver');
                });

            if ($search) {
                $query->where('name', 'like', '%' . $search . '%');
            }

            $drivers = $query->orderBy('name', 'ASC')->get(['id', 'name', 'phone', 'profle_image']);

            return response()->json([
                'status' => 200,
                'message' => 'Active drivers retrieved successfully',
                'data' => $drivers
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to retrieve available drivers',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
