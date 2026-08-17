<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehiclePucc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehiclePuccController extends Controller
{
    public function storeOrUpdate(Request $request, $vehicle_id)
    {
        try {
            $vehicle = Vehicle::findOrFail($vehicle_id);
            $pucc_id = $request->input('id');

            $validator = Validator::make($request->all(), [
                'certificate_number' => 'required|string|max:255',
                'issue_date' => 'required|date',
                'valid_upto' => 'required|date|after_or_equal:issue_date',
                'status' => 'nullable|in:active,expired',
                'document' => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 400, 'message' => 'Validation failed', 'errors' => $validator->errors()], 400);
            }

            $data = $validator->validated();
            unset($data['document']);

            if ($request->hasFile('document')) {
                $path = $request->file('document')->store("vehicles/{$vehicle->id}/puccs", 'public');
                $data['document_path'] = $path;
            }

            $matchThese = ['vehicle_id' => $vehicle->id];
            if ($pucc_id) {
                $matchThese['id'] = $pucc_id;
            }

            $pucc = VehiclePucc::updateOrCreate($matchThese, $data);

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle PUCC saved successfully',
                'data' => $pucc,
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'message' => 'Failed to save vehicle PUCC', 'error' => $e->getMessage()], 500);
        }
    }
}
