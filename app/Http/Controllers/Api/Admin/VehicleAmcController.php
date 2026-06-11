<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleAmc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleAmcController extends Controller
{
    public function storeOrUpdate(Request $request, $vehicle_id)
    {
        try {
            $vehicle = Vehicle::findOrFail($vehicle_id);
            $amc_id = $request->input('id');

            $validator = Validator::make($request->all(), [
                'amc_provider' => 'required|string|max:255',
                'amc_number' => 'nullable|string|max:255',
                'amc_type' => 'nullable|string|max:255',
                'contract_number' => 'nullable|string|max:255',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'avg_running_year' => 'nullable|string|max:255',
                'cost' => 'nullable|numeric',
                'amc_gst' => 'nullable|numeric',
                'amc_tcs' => 'nullable|numeric',
                'total_cost' => 'nullable|numeric',
                'payment_schedule' => 'nullable|string|max:255',
                'payment_start_date' => 'nullable|date',
                'status' => 'nullable|in:active,expired,renewed',
                'document' => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 400, 'message' => 'Validation failed', 'errors' => $validator->errors()], 400);
            }

            $data = $validator->validated();
            unset($data['document']);

            if ($request->hasFile('document')) {
                $path = $request->file('document')->store("vehicles/{$vehicle->id}/amcs", 'public');
                $data['document_path'] = $path;
            }

            $matchThese = ['vehicle_id' => $vehicle->id];
            if ($amc_id) {
                $matchThese['id'] = $amc_id;
            }

            $amc = VehicleAmc::updateOrCreate($matchThese, $data);

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle AMC saved successfully',
                'data' => $amc,
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'message' => 'Failed to save vehicle AMC', 'error' => $e->getMessage()], 500);
        }
    }
}
