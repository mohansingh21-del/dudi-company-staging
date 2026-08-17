<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);
            $status = $request->input('status', null); // 'active', 'inactive', 'draft'

            $query = Vehicle::with(['vehicleType', 'vehicleModel']);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('vehicle_number', 'like', '%' . $search . '%')
                        ->orWhere('owner_name', 'like', '%' . $search . '%')
                        ->orWhereHas('vehicleModel', function ($mq) use ($search) {
                            $mq->where('name', 'like', '%' . $search . '%');
                        })
                        ->orWhereHas('vehicleType', function ($tq) use ($search) {
                            $tq->where('name', 'like', '%' . $search . '%');
                        });
                });
            }

            if ($status) {
                $query->where('status', $status);
            }

            if ($limit) {
                $vehicles = $query->orderBy('created_at', 'DESC')->paginate($limit, ['*'], 'page', $page);
                $vehicles->getCollection()->transform(function ($vehicle) {
                    return [
                        'id' => $vehicle->id,
                        'vehicle_number' => $vehicle->vehicle_number,
                        'owner_name' => $vehicle->owner_name,
                        'emission_norm' => $vehicle->emission_norm,
                        'status' => $vehicle->status,
                        'vehicle_type' => $vehicle->vehicleType,
                        'vehicle_model' => $vehicle->vehicleModel,
                    ];
                });

                $response = [
                    'data' => $vehicles->items(),
                    'pagination' => [
                        'total' => $vehicles->total(),
                        'current_page' => $vehicles->currentPage(),
                        'per_page' => $vehicles->perPage(),
                        'last_page' => $vehicles->lastPage(),
                        'from' => $vehicles->firstItem(),
                        'to' => $vehicles->lastItem(),
                        'next_page_url' => $vehicles->nextPageUrl(),
                        'previous_page_url' => $vehicles->previousPageUrl(),
                    ]
                ];
            } else {
                $vehicles = $query->orderBy('created_at', 'DESC')->get();
                $response = [
                    'data' => $vehicles->map(function ($vehicle) {
                        return [
                            'id' => $vehicle->id,
                            'vehicle_number' => $vehicle->vehicle_number,
                            'owner_name' => $vehicle->owner_name,
                            'emission_norm' => $vehicle->emission_norm,
                            'status' => $vehicle->status,
                            'vehicle_type' => $vehicle->vehicleType,
                            'vehicle_model' => $vehicle->vehicleModel,
                        ];
                    }),
                    'pagination' => [
                        'total' => $vehicles->count(),
                        'current_page' => 1,
                        'per_page' => $vehicles->count(),
                        'last_page' => 1,
                        'from' => $vehicles->isEmpty() ? 0 : 1,
                        'to' => $vehicles->count(),
                        'next_page_url' => null,
                        'previous_page_url' => null,
                    ]
                ];
            }

            return response()->json([
                'status' => 200,
                'message' => 'Vehicles fetched successfully',
                'data' => $response['data'],
                'pagination' => $response['pagination'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch vehicles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $id = $request->input('id', null);
        try {
            $validator = Validator::make($request->all(), [
                'vehicle_number' => 'required|string|max:255|unique:vehicles,vehicle_number,' . $id,
                'owner_name' => 'nullable|string|max:255',
                'vehicle_type_id' => 'required|exists:vehicle_types,id',
                'vehicle_body_type' => 'nullable|string|max:255',
                'vehicle_length' => 'nullable|string|max:255',
                'vehicle_condition' => 'nullable|string|max:255',
                'vehicle_manufacturer_id' => 'required|exists:vehicle_manufacturers,id',
                'vehicle_model_id' => 'required|exists:vehicle_models,id',
                'registration_date' => 'nullable|date',
                'body_total_volumetric_capacity' => 'nullable|string|max:255',
                'chassis_number' => 'nullable|string|max:255',
                'engine_number' => 'nullable|string|max:255',
                'color' => 'nullable|string|max:255',
                'wheel_base' => 'nullable|string|max:255',
                'emission_norm' => 'nullable|string|max:255',
                'horse_power' => 'nullable|string|max:255',
                'laden_weight' => 'nullable|string|max:255',
                'unladen_weight' => 'nullable|string|max:255',
                'gross_vehicle_weight' => 'nullable|string|max:255',
                'fuel_type' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $vehicle = $id ? Vehicle::findOrFail($id) : new Vehicle();
            $vehicle->fill($validator->validated());
            if (!$id) {
                $vehicle->is_active = 1;
            }
            $vehicle->save();

            return response()->json([
                'status' => $id ? 200 : 201,
                'message' => $id ? 'Vehicle updated successfully' : 'Vehicle general details created successfully',
                'data' => $vehicle,
            ], $id ? 200 : 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to ' . ($id ? 'update' : 'create') . ' vehicle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $vehicle = Vehicle::with([
                'invoices',
                'amcs',
                'permits',
                'insurances',
                'puccs',
                'fitnessCertificates',
                'documents'
            ])->findOrFail($id);

            return response()->json([
                'status' => 200,
                'message' => 'Vehicle fetched successfully',
                'data' => $vehicle,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch vehicle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
