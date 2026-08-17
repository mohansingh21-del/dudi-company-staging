<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleMaintenanceController extends Controller
{
    /**
     * Display a listing of the vehicle's maintenance records with dashboard summary metrics.
     *
     * @param  int  $vehicle_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($vehicle_id)
    {
        try {
            $vehicle = Vehicle::findOrFail($vehicle_id);

            // Fetch all maintenance records sorted by service date descending
            $maintenances = VehicleMaintenance::where('vehicle_id', $vehicle_id)
                ->orderBy('service_date', 'DESC')
                ->get();

            // Calculate Dashboard Summaries
            $totalCost = $maintenances->sum('cost');

            $latestService = $maintenances->first();
            $lastServiceDate = $latestService ? $latestService->service_date : null;

            // Get the next upcoming service due date
            $nextService = VehicleMaintenance::where('vehicle_id', $vehicle_id)
                ->where('next_service_due_date', '>=', now()->toDateString())
                ->orderBy('next_service_due_date', 'ASC')
                ->first();

            // Fallback to latest record's next service date if no upcoming future date exists
            $nextServiceDueDate = $nextService ? $nextService->next_service_due_date : ($latestService ? $latestService->next_service_due_date : null);
            $nextServiceDueKm = $nextService ? $nextService->next_service_km : ($latestService ? $latestService->next_service_km : null);

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle maintenance history retrieved successfully',
                'summary' => [
                    'total_maintenance_cost' => $totalCost,
                    'last_service_date' => $lastServiceDate,
                    'next_service_due_date' => $nextServiceDueDate,
                    'next_service_due_km' => $nextServiceDueKm
                ],
                'data' => $maintenances
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to retrieve maintenance records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created maintenance record in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $vehicle_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, $vehicle_id)
    {
        try {
            $vehicle = Vehicle::findOrFail($vehicle_id);
            $id = $request->input('id');

            $validator = Validator::make($request->all(), [
                'id' => 'nullable|integer|exists:vehicle_maintenances,id',
                'service_type' => 'required|string|max:255',
                'service_date' => 'required|date',
                'cost' => 'required|numeric|min:0',
                'kilometer_reading' => 'required|integer|min:0',
                'vendor_name' => 'required|string|max:255',
                'next_service_due_date' => 'required|date|after_or_equal:service_date',
                'next_service_km' => 'nullable|integer|gte:kilometer_reading',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($id) {
                $maintenance = VehicleMaintenance::where('vehicle_id', $vehicle->id)->findOrFail($id);
                $maintenance->update([
                    'service_type' => $request->input('service_type'),
                    'service_date' => $request->input('service_date'),
                    'cost' => $request->input('cost'),
                    'kilometer_reading' => $request->input('kilometer_reading'),
                    'vendor_name' => $request->input('vendor_name'),
                    'next_service_due_date' => $request->input('next_service_due_date'),
                    'next_service_km' => $request->input('next_service_km'),
                ]);

                return response()->json([
                    'status' => 200,
                    'message' => 'Vehicle maintenance record updated successfully',
                    'data' => $maintenance
                ], 200);
            } else {
                $maintenance = VehicleMaintenance::create([
                    'vehicle_id' => $vehicle->id,
                    'service_type' => $request->input('service_type'),
                    'service_date' => $request->input('service_date'),
                    'cost' => $request->input('cost'),
                    'kilometer_reading' => $request->input('kilometer_reading'),
                    'vendor_name' => $request->input('vendor_name'),
                    'next_service_due_date' => $request->input('next_service_due_date'),
                    'next_service_km' => $request->input('next_service_km'),
                ]);

                return response()->json([
                    'status' => 201,
                    'message' => 'Vehicle maintenance record saved successfully',
                    'data' => $maintenance
                ], 201);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to save maintenance record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified maintenance record.
     *
     * @param  int  $vehicle_id
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($vehicle_id, $id)
    {
        try {
            $maintenance = VehicleMaintenance::where('vehicle_id', $vehicle_id)->findOrFail($id);

            return response()->json([
                'status' => 200,
                'message' => 'Maintenance record fetched successfully',
                'data' => $maintenance
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Maintenance record not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified maintenance record in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $vehicle_id
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $vehicle_id, $id)
    {
        $request->merge(['id' => $id]);
        return $this->store($request, $vehicle_id);
    }

    /**
     * Remove the specified maintenance record from storage.
     *
     * @param  int  $vehicle_id
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($vehicle_id, $id)
    {
        try {
            $maintenance = VehicleMaintenance::where('vehicle_id', $vehicle_id)->findOrFail($id);
            $maintenance->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle maintenance record deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to delete maintenance record',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
