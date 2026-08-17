<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehiclePermit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehiclePermitController extends Controller
{
    public function storeOrUpdate(Request $request, $vehicle_id)
    {
        try {
            $vehicle = Vehicle::findOrFail($vehicle_id);
            $permit_id = $request->input('id');

            $validator = Validator::make($request->all(), [
                'permit_type' => 'required|in:national,state',
                'state' => 'required_if:permit_type,state|nullable|string|max:255',
                'permit_number' => 'required|string|max:255',
                'permit_holder' => 'nullable|string|max:255',
                'father_name' => 'nullable|string|max:255',
                'address' => 'nullable|string',
                'registration_date' => 'nullable|date',
                'payment_terms' => 'nullable|string|max:255',
                'compliance_document' => 'nullable|string|max:255',
                'document_charges' => 'nullable|numeric',
                'NP_dated' => 'nullable|date',
                'valid_from' => 'nullable|date',
                'valid_upto' => 'nullable|date',
                'status' => 'nullable|in:active,expired',
                'document' => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 400, 'message' => 'Validation failed', 'errors' => $validator->errors()], 400);
            }

            $data = $validator->validated();
            unset($data['document']);

            if ($request->hasFile('document')) {
                $path = $request->file('document')->store("vehicles/{$vehicle->id}/permits", 'public');
                $data['document_path'] = $path;
            }

            $matchThese = ['vehicle_id' => $vehicle->id];
            if ($permit_id) {
                $matchThese['id'] = $permit_id;
            }

            $permit = VehiclePermit::updateOrCreate($matchThese, $data);

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle Permit saved successfully',
                'data' => $permit,
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'message' => 'Failed to save vehicle permit', 'error' => $e->getMessage()], 500);
        }
    }
}
