<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleInsurance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleInsuranceController extends Controller
{
    public function storeOrUpdate(Request $request, $vehicle_id)
    {
        try {
            $vehicle = Vehicle::findOrFail($vehicle_id);
            $insurance_id = $request->input('id');

            $validator = Validator::make($request->all(), [
                'provider' => 'required|string|max:255',
                'policy_number' => 'required|string|max:255|unique:vehicle_insurances,policy_number,' . $insurance_id,
                'insurance_type' => 'required|in:comprehensive,third_party',
                'issue_date' => 'required|date',
                'expiry_date' => 'required|date|after_or_equal:issue_date',
                'od_premium_amt' => 'nullable|numeric',
                'od_deductions' => 'nullable|numeric',
                'od_additions' => 'nullable|numeric',
                'total_od_premium' => 'nullable|numeric',
                'tp_premium' => 'nullable|numeric',
                'tp_deductions' => 'nullable|numeric',
                'tp_additions' => 'nullable|numeric',
                'total_tp_premium' => 'nullable|numeric',
                'total_premium' => 'nullable|numeric',
                'gst_amount' => 'nullable|numeric',
                'cess_amount' => 'nullable|numeric',
                'total' => 'nullable|numeric',
                'nominee_name' => 'nullable|string|max:255',
                'relation' => 'nullable|string|max:255',
                'age' => 'nullable|integer',
                'status' => 'nullable|in:active,expired',
                'document' => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 400, 'message' => 'Validation failed', 'errors' => $validator->errors()], 400);
            }

            $data = $validator->validated();
            unset($data['document']);

            if ($request->hasFile('document')) {
                $path = $request->file('document')->store("vehicles/{$vehicle->id}/insurances", 'public');
                $data['document_path'] = $path;
            }

            $matchThese = ['vehicle_id' => $vehicle->id];
            if ($insurance_id) {
                $matchThese['id'] = $insurance_id;
            }

            $insurance = VehicleInsurance::updateOrCreate($matchThese, $data);

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle Insurance saved successfully',
                'data' => $insurance,
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'message' => 'Failed to save vehicle insurance', 'error' => $e->getMessage()], 500);
        }
    }
}
