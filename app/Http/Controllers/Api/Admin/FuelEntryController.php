<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FuelEntry;
use Illuminate\Support\Facades\Validator;
use App\Models\Vehicle;
use App\Models\Employee;

class FuelEntryController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);

            $fuelEntries = FuelEntry::with(['vehicle', 'driver']);
            if ($search) {
                $fuelEntries->whereHas('vehicle', function ($query) use ($search) {
                    $query->where('vehicle_number', 'like', "%{$search}%")
                        ->orWhere('vehicle_name', 'like', "%{$search}%")
                        ->orWhere('chassis_number', 'like', "%{$search}%");
                })->orWhereHas('driver', function ($query) use ($search) {
                    $query->where('employee_name', 'like', "%{$search}%");
                });
            }
            if ($limit) {
                $fuelEntries = $fuelEntries->orderBy('created_at', 'DESC')->paginate($limit, ['*'], 'page', $page);
                $fuelEntries->getCollection()->transform(function ($fuelEntry) {
                    return [
                        'id' => $fuelEntry->id,
                        'vehicle_number' => $fuelEntry->vehicle->vehicle_number,
                        'driver_name' => $fuelEntry->driver->employee_name,
                        'fuel_type' => $fuelEntry->fuel_type,
                        'fuel_quantity' => $fuelEntry->fuel_quantity,
                        'amount' => $fuelEntry->amount,
                        'entry_date' => $fuelEntry->entry_date,
                    ];
                });
                $response = [
                    'data' => $fuelEntries->items(),
                    'pagination' => [
                        'total' => $fuelEntries->total(),
                        'current_page' => $fuelEntries->currentPage(),
                        'per_page' => $fuelEntries->perPage(),
                        'last_page' => $fuelEntries->lastPage(),
                        'from' => $fuelEntries->firstItem(),
                        'to' => $fuelEntries->lastItem(),
                        'next_page_url' => $fuelEntries->nextPageUrl(),
                        'previous_page_url' => $fuelEntries->previousPageUrl(),
                    ]
                ];

            } else {
                $fuelEntries = $fuelEntries->orderBy('created_at', 'DESC')->get();
                $fuelEntries->transform(function ($fuelEntry) {
                    return [
                        'id' => $fuelEntry->id,
                        'vehicle_number' => $fuelEntry->vehicle->vehicle_number,
                        'driver_name' => $fuelEntry->driver->employee_name,
                        'fuel_type' => $fuelEntry->fuel_type,
                        'fuel_quantity' => $fuelEntry->fuel_quantity,
                        'amount' => $fuelEntry->amount,
                        'entry_date' => $fuelEntry->entry_date,
                    ];
                });

                $response = [
                    'data' => $fuelEntries,
                    'pagination' => [
                        'total' => $fuelEntries->count(),
                        'current_page' => 1,
                        'per_page' => $fuelEntries->count(),
                        'last_page' => 1,
                        'from' => $fuelEntries->isEmpty() ? 0 : 1,
                        'to' => $fuelEntries->count(),
                        'next_page_url' => null,
                        'previous_page_url' => null,
                    ]
                ];
            }
            return response()->json([
                'status' => 200,
                'message' => 'Fuel entries fetched successfully',
                'data' => $response['data'],
                'pagination' => $response['pagination']
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch fuel entries',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function store(Request $request)
    {
        $id = $request->input('id');
        try {
            $validator = Validator::make($request->all(), [
                'vehicle_id' => 'required|exists:vehicles,id',
                'driver_id' => 'required|exists:employees,id',
                'fuel_type' => 'required|in:diesel,petrol,cng,lpg,electricity',
                'fuel_quantity' => 'required|numeric|min:0',
                'amount' => 'required|numeric|min:0',
                'entry_date' => 'required|date',
                'fuel_station' => 'nullable|string',
                'current_odometer' => 'nullable|integer',
                'mileage' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $fuelEntry = $id ? FuelEntry::findOrFail($id) : new FuelEntry();
            $fuelEntry->vehicle_id = $request->vehicle_id;
            $fuelEntry->driver_id = $request->driver_id;
            $fuelEntry->fuel_type = $request->fuel_type;
            $fuelEntry->quantity = $request->fuel_quantity;
            $fuelEntry->amount = $request->amount;
            $fuelEntry->transaction_date = $request->entry_date;
            $fuelEntry->fuel_station = $request->fuel_station;
            $fuelEntry->current_odometer = $request->current_odometer;
            $fuelEntry->mileage = $request->mileage;
            $fuelEntry->save();

            return response()->json([
                'status' => 200,
                'message' => 'Fuel entry ' . ($id ? 'updated' : 'created') . ' successfully',
                'data' => $fuelEntry,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to create fuel entry',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($vehicle_id)
    {
        try {
            $fuelEntries = FuelEntry::with(['vehicle', 'driver'])
                ->where('vehicle_id', $vehicle_id)
                ->orderBy('transaction_date', 'DESC')
                ->get();

            $data = $fuelEntries->map(function ($fuelEntry) {
                return [
                    'id' => $fuelEntry->id,
                    'vehicle_id' => $fuelEntry->vehicle_id,
                    'vehicle_number' => optional($fuelEntry->vehicle)->vehicle_number,
                    'driver_id' => $fuelEntry->driver_id,
                    'driver_name' => optional($fuelEntry->driver)->employee_name,
                    'fuel_type' => $fuelEntry->fuel_type,
                    'fuel_quantity' => $fuelEntry->quantity,
                    'amount' => $fuelEntry->amount,
                    'entry_date' => $fuelEntry->transaction_date ? $fuelEntry->transaction_date->toDateString() : null,
                    'fuel_station' => $fuelEntry->fuel_station,
                    'current_odometer' => $fuelEntry->current_odometer,
                    'mileage' => $fuelEntry->mileage,
                    'created_at' => $fuelEntry->created_at,
                    'updated_at' => $fuelEntry->updated_at,
                ];
            });

            return response()->json([
                'status' => 200,
                'message' => 'Fuel entries fetched successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch fuel entries',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
