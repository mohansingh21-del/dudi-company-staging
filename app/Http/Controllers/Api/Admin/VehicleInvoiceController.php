<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class VehicleInvoiceController extends Controller
{
    public function storeOrUpdate(Request $request, $vehicle_id)
    {
        try {
            $vehicle = Vehicle::findOrFail($vehicle_id);
            $invoice_id = $request->input('id');

            $validator = Validator::make($request->all(), [
                'dealer_invoice_number' => 'required|string|max:255|unique:vehicle_invoices,dealer_invoice_number,' . $invoice_id,
                'invoice_date' => 'required|date',
                'GRN_date' => 'nullable|date',
                'base_price' => 'required|numeric',
                'invoice_price' => 'required|numeric',
                'dealer_name' => 'required|string|max:255',
                'financed_by' => 'nullable|string|max:255',
                'document' => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 400, 'message' => 'Validation failed', 'errors' => $validator->errors()], 400);
            }

            $data = $validator->validated();
            unset($data['document']);

            if ($request->hasFile('document')) {
                $path = $request->file('document')->store("vehicles/{$vehicle->id}/invoices", 'public');
                $data['document_path'] = $path;
            }

            $matchThese = ['vehicle_id' => $vehicle->id];
            if ($invoice_id) {
                $matchThese['id'] = $invoice_id;
            }

            $invoice = VehicleInvoice::updateOrCreate($matchThese, $data);

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle Invoice saved successfully',
                'data' => $invoice,
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'message' => 'Failed to save vehicle invoice', 'error' => $e->getMessage()], 500);
        }
    }
}
