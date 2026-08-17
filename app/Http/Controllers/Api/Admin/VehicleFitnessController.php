<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleFitnessCertificate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleFitnessController extends Controller
{
    public function storeOrUpdate(Request $request, $vehicle_id)
    {
        try {
            $vehicle = Vehicle::findOrFail($vehicle_id);
            $fitness_id = $request->input('id');

            $validator = Validator::make($request->all(), [
                'certificate_number' => 'required|string|max:255',
                'rto_office' => 'required|string|max:255',
                'issue_date' => 'required|date',
                'valid_upto' => 'required|date|after_or_equal:issue_date',
                'status' => 'nullable|in:active,expired',
                'document' => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 422, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            $data = $validator->validated();
            unset($data['document']);

            if ($request->hasFile('document')) {
                $path = $request->file('document')->store("vehicles/{$vehicle->id}/fitness", 'public');
                $data['document_path'] = $path;
            }

            $matchThese = ['vehicle_id' => $vehicle->id];
            if ($fitness_id) {
                $matchThese['id'] = $fitness_id;
            }

            $fitness = VehicleFitnessCertificate::updateOrCreate($matchThese, $data);

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle Fitness Certificate saved successfully',
                'data' => $fitness,
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'message' => 'Failed to save vehicle fitness certificate', 'error' => $e->getMessage()], 500);
        }
    }
}
