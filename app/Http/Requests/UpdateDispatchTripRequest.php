<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\ShiftEquipmentAllocation;
use App\Models\DispatchTrip;
use Carbon\Carbon;

class UpdateDispatchTripRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'driver_id' => 'sometimes|required|exists:employees,id',
            'excavator_equipment_id' => 'sometimes|nullable|exists:equipment_names,id',
            'loading_point_id' => 'sometimes|required|exists:site_points,id',
            'dumping_point_id' => 'sometimes|required|exists:site_points,id',
            'trip_date_time' => 'sometimes|required|date_format:Y-m-d H:i:s',
            'start_time' => 'sometimes|required|date_format:H:i:s',
            'end_time' => 'sometimes|required|date_format:H:i:s',
            'quantity_bcm' => 'sometimes|required|numeric|gt:0',
            'distance_meters' => 'nullable|numeric|gt:0',
            'total_cycles' => 'sometimes|nullable|integer|gt:0',
            'site_id' => 'sometimes|required|exists:sites,id',
            'dumper_equipment_id' => 'sometimes|required|exists:equipment_names,id',
            'shift_plan_id' => 'sometimes|required|exists:shift_plans,id',
            'shift_id' => 'sometimes|required|exists:shifts,id',
            'trip_reference_no' => 'sometimes|required|string',
            'status' => 'sometimes|required|string',
            'created_by' => 'sometimes|required|exists:users,id',
            'created_date' => 'sometimes|required|date_format:Y-m-d H:i:s',
            'cycle_time_minutes' => 'sometimes|required|numeric',
        ];
    }

    protected function prepareForValidation()
    {
        $tripId = $this->route('id');
        $trip = \App\Models\DispatchTrip::find($tripId);
        if ($trip) {
            $siteId = $this->has('site_id') ? $this->input('site_id') : $trip->site_id;
            $shiftId = $this->has('shift_id') ? $this->input('shift_id') : $trip->shift_id;
            $tripDateTime = $this->has('trip_date_time') ? $this->input('trip_date_time') : ($trip->trip_date_time ? $trip->trip_date_time->toDateTimeString() : null);

            if ($tripDateTime) {
                $planningDate = \Carbon\Carbon::parse($tripDateTime)->format('Y-m-d');
                $shiftPlan = \App\Models\ShiftPlan::where('site_id', $siteId)
                    ->where('shift_id', $shiftId)
                    ->where('planning_date', $planningDate)
                    ->first();
                if ($shiftPlan) {
                    $this->merge(['shift_plan_id' => $shiftPlan->id]);
                }
            }
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $tripId = $this->route('id');
            $trip = DispatchTrip::with('shiftPlan.shift')->find($tripId);

            if ($trip) {
                // Reject if request has trip_reference_no, cycle_time_minutes, created_by, created_date with different values
                $immutables = [
                    'trip_reference_no' => $trip->trip_reference_no,
                    'cycle_time_minutes' => $trip->cycle_time_minutes,
                    'created_by' => $trip->created_by,
                    'created_date' => $trip->created_at ? $trip->created_at->toDateTimeString() : null,
                    'status' => $trip->status,
                ];

                foreach ($immutables as $field => $dbValue) {
                    if ($this->has($field)) {
                        $reqValue = $this->input($field);
                        if ($field === 'created_date') {
                            if ($dbValue !== null && $reqValue !== null && Carbon::parse($reqValue)->ne(Carbon::parse($dbValue))) {
                                $validator->errors()->add($field, "The {$field} field is read-only and cannot be changed.");
                            }
                        } else {
                            if ((string)$reqValue !== (string)$dbValue) {
                                $validator->errors()->add($field, "The {$field} field is read-only and cannot be changed.");
                            }
                        }
                    }
                }

                // Resolve new shift_plan_id based on updated site_id, shift_id, and/or trip_date_time
                $siteId = $this->has('site_id') ? $this->input('site_id') : $trip->site_id;
                $shiftId = $this->has('shift_id') ? $this->input('shift_id') : $trip->shift_id;
                $tripDateTime = $this->has('trip_date_time') ? $this->input('trip_date_time') : ($trip->trip_date_time ? $trip->trip_date_time->toDateTimeString() : null);

                $resolvedShiftPlan = null;
                if ($tripDateTime) {
                    $planningDate = Carbon::parse($tripDateTime)->format('Y-m-d');
                    $resolvedShiftPlan = \App\Models\ShiftPlan::where('site_id', $siteId)
                        ->where('shift_id', $shiftId)
                        ->where('planning_date', $planningDate)
                        ->first();

                    if (!$resolvedShiftPlan) {
                        $validator->errors()->add('shift_plan_id', 'No active shift plan found for the selected site, shift, and date.');
                    }
                }

                $currentShiftPlanId = $resolvedShiftPlan ? $resolvedShiftPlan->id : $trip->shift_plan_id;
                $currentShiftPlan = $resolvedShiftPlan ?? $trip->shiftPlan;

                // Check allocation of dumper if being updated
                if ($this->has('dumper_equipment_id')) {
                    $dumperId = $this->input('dumper_equipment_id');
                    $dumperAllocation = ShiftEquipmentAllocation::where('shift_plan_id', $currentShiftPlanId)
                        ->where('equipment_name_id', $dumperId)
                        ->first();
                    if (!$dumperAllocation) {
                        $validator->errors()->add('dumper_equipment_id', 'Selected Dumper Is Not Assigned To Current Shift.');
                    } else {
                        // The excavator (either passed in request or current in database) must match the dumper's parent excavator
                        $excavatorId = $this->input('excavator_equipment_id') ?? $trip->excavator_equipment_id;
                        if ($excavatorId && $dumperAllocation->parent_equipment_id != $excavatorId) {
                            $validator->errors()->add('excavator_equipment_id', 'Selected Excavator Is Not Mapped To Selected Dumper In Shift Allocation.');
                        }
                    }
                }

                // Check allocation of excavator if being updated
                if ($this->has('excavator_equipment_id')) {
                    $excavatorId = $this->input('excavator_equipment_id');
                    $dumperId = $this->input('dumper_equipment_id') ?? $trip->dumper_equipment_id; // dumper is read-only if not passed
                    if ($dumperId) {
                        $dumperAllocation = ShiftEquipmentAllocation::where('shift_plan_id', $currentShiftPlanId)
                            ->where('equipment_name_id', $dumperId)
                            ->first();
                        if ($dumperAllocation) {
                            if ($excavatorId && $dumperAllocation->parent_equipment_id != $excavatorId) {
                                $validator->errors()->add('excavator_equipment_id', 'Selected Excavator Is Not Mapped To Selected Dumper In Shift Allocation.');
                            }
                        }
                    }

                    if ($excavatorId) {
                        $excavatorAllocated = ShiftEquipmentAllocation::where('shift_plan_id', $currentShiftPlanId)
                            ->where('equipment_name_id', $excavatorId)
                            ->exists();
                        if (!$excavatorAllocated) {
                            $validator->errors()->add('excavator_equipment_id', 'Selected Excavator Is Not Assigned To Current Shift.');
                        }
                    }
                }

                // Check mapping of loading_point_id and dumping_point_id to site_id if being updated
                if ($this->has('loading_point_id')) {
                    $loadingPointId = $this->input('loading_point_id');
                    if ($loadingPointId) {
                        $loadingPoint = \App\Models\SitePoint::where('site_id', $siteId)
                            ->where('id', $loadingPointId)
                            ->where('is_active', true)
                            ->where('type', 'loading')
                            ->exists();
                        if (!$loadingPoint) {
                            $validator->errors()->add('loading_point_id', 'Selected Loading Point Is Invalid or Not Mapped To Current Site.');
                        }
                    }
                }

                if ($this->has('dumping_point_id')) {
                    $dumpingPointId = $this->input('dumping_point_id');
                    if ($dumpingPointId) {
                        $dumpingPoint = \App\Models\SitePoint::where('site_id', $siteId)
                            ->where('id', $dumpingPointId)
                            ->where('is_active', true)
                            ->where('type', 'dumping')
                            ->exists();
                        if (!$dumpingPoint) {
                            $validator->errors()->add('dumping_point_id', 'Selected Dumping Point Is Invalid or Not Mapped To Current Site.');
                        }
                    }
                }

                // Validate end_time is after start_time if both are supplied or partially supplied
                $startTimeInput = $this->input('start_time');
                $endTimeInput = $this->input('end_time');

                if ($startTimeInput && preg_match('/^\d{2}:\d{2}:\d{2}$/', $startTimeInput)) {
                    $startTime = \App\Services\DispatchTripService::resolveDateTimeFromTime($startTimeInput, $currentShiftPlan);
                } else {
                    $startTime = $trip->start_time ? $trip->start_time->format('Y-m-d H:i:s') : null;
                }

                if ($endTimeInput && preg_match('/^\d{2}:\d{2}:\d{2}$/', $endTimeInput)) {
                    $endTime = \App\Services\DispatchTripService::resolveDateTimeFromTime($endTimeInput, $currentShiftPlan);
                } else {
                    if ($startTime && $trip->end_time) {
                        $planningDate = Carbon::parse($currentShiftPlan->planning_date)->format('Y-m-d');
                        $endTimeOnly = $trip->end_time->format('H:i:s');
                        $endDateTime = Carbon::parse($planningDate . ' ' . $endTimeOnly);
                        if ($endDateTime->lte(Carbon::parse($startTime))) {
                            $endDateTime->addDay();
                        }
                        $endTime = $endDateTime->format('Y-m-d H:i:s');
                    } else {
                        $endTime = $trip->end_time ? $trip->end_time->format('Y-m-d H:i:s') : null;
                    }
                }

                if ($startTime && $endTime) {
                    if (Carbon::parse($endTime)->lte(Carbon::parse($startTime))) {
                        $validator->errors()->add('end_time', 'The end time must be after the start time.');
                    }
                }
            } else {
                $validator->errors()->add('id', 'Trip Record Not Found');
            }
        });
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status'  => 422,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422)
        );
    }
}
