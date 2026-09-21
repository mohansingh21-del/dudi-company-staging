<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\ShiftEquipmentAllocation;
use App\Models\DispatchTrip;

class StoreDispatchTripRequest extends FormRequest
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
            'shift_plan_id' => 'required_without:shift_id|exists:shift_plans,id',
            'shift_id' => ['required_without:shift_plan_id', 'exists:shifts,id', new \App\Rules\ActiveShift()],
            'site_id' => 'required|exists:sites,id',
            'dumper_equipment_id' => 'required|exists:equipment_names,id',
            'driver_id' => 'required|exists:employees,id',
            'excavator_equipment_id' => 'nullable|exists:equipment_names,id',
            'loading_point_id' => 'required|exists:site_points,id',
            'dumping_point_id' => 'required|exists:site_points,id',
            'trip_date_time' => 'nullable|date_format:Y-m-d H:i:s',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s',
            'quantity_bcm' => 'required|numeric|gt:0|max:' . DispatchTrip::MAX_QUANTITY_BCM,
            'distance_meters' => 'nullable|numeric|gt:0|max:' . DispatchTrip::MAX_DISTANCE_METERS,
            'total_cycles' => 'required|integer|gt:0|max:' . DispatchTrip::MAX_TOTAL_CYCLES,
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'quantity_bcm.max' => 'Quantity Moved (BCM) may not be greater than 99,999,999.99.',
            'quantity_bcm.gt' => 'Quantity Moved (BCM) must be greater than 0.',
            'quantity_bcm.numeric' => 'Quantity Moved (BCM) must be a number.',
            'quantity_bcm.required' => 'Quantity Moved (BCM) is required.',
            'distance_meters.max' => 'Distance may not be greater than 99,999,999.99 meters.',
            'total_cycles.max' => 'Total cycles may not be greater than 2,147,483,647.',
        ];
    }

    protected function prepareForValidation()
    {
        $shiftPlanId = $this->input('shift_plan_id');
        $siteId = $this->input('site_id');
        $shiftId = $this->input('shift_id');
        $tripDateTime = $this->input('trip_date_time') ?? \Carbon\Carbon::now()->toDateTimeString();

        if (!$shiftPlanId && $siteId && $shiftId) {
            $planningDate = \Carbon\Carbon::parse($tripDateTime)->format('Y-m-d');
            $shiftPlan = \App\Models\ShiftPlan::where('site_id', $siteId)
                ->where('shift_id', $shiftId)
                ->where('planning_date', $planningDate)
                ->first();
            if ($shiftPlan) {
                $this->merge(['shift_plan_id' => $shiftPlan->id]);
                $shiftPlanId = $shiftPlan->id;
            }
        }

        $dumperId = $this->input('dumper_equipment_id');
        $excavatorId = $this->input('excavator_equipment_id');

        if ($shiftPlanId && $dumperId && (is_null($excavatorId) || $excavatorId === '')) {
            $dumperAllocation = \App\Models\ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlanId)
                ->where('equipment_name_id', $dumperId)
                ->first();
            if ($dumperAllocation && $dumperAllocation->parent_equipment_id) {
                $this->merge(['excavator_equipment_id' => $dumperAllocation->parent_equipment_id]);
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
            $shiftPlanId = $this->input('shift_plan_id');
            $siteId = $this->input('site_id');
            $shiftId = $this->input('shift_id');

            if (!$shiftPlanId && $siteId && $shiftId) {
                $validator->errors()->add('shift_plan_id', 'No active shift plan found for the selected site, shift, and date.');
            } else if ($shiftPlanId) {
                $shiftPlanObj = \App\Models\ShiftPlan::find($shiftPlanId);
                if ($shiftPlanObj) {
                    if ($siteId && $shiftPlanObj->site_id != $siteId) {
                        $validator->errors()->add('site_id', 'The selected site_id does not match the shift plan site.');
                    }
                    if ($shiftId && $shiftPlanObj->shift_id != $shiftId) {
                        $validator->errors()->add('shift_id', 'The selected shift_id does not match the shift plan shift.');
                    }
                }
            }

            $dumperId = $this->input('dumper_equipment_id');
            $excavatorId = $this->input('excavator_equipment_id');

            if ($shiftPlanId) {
                // (a) Check if dumper is allocated to the shift plan
                if ($dumperId) {
                    $dumperAllocation = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlanId)
                        ->where('equipment_name_id', $dumperId)
                        ->first();
                    if (!$dumperAllocation) {
                        $validator->errors()->add('dumper_equipment_id', 'Selected Dumper Is Not Assigned To Current Shift.');
                    } else {
                        // Check if excavator matches the parent_equipment_id of the dumper allocation
                        if ($excavatorId && $dumperAllocation->parent_equipment_id != $excavatorId) {
                            $validator->errors()->add('excavator_equipment_id', 'Selected Excavator Is Not Mapped To Selected Dumper In Shift Allocation.');
                        }
                    }
                }

                // (b) Check if excavator is allocated to the shift plan
                if ($excavatorId) {
                    $excavatorAllocated = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlanId)
                        ->where('equipment_name_id', $excavatorId)
                        ->exists();
                    if (!$excavatorAllocated) {
                        $validator->errors()->add('excavator_equipment_id', 'Selected Excavator Is Not Assigned To Current Shift.');
                    }
                }

                // (c) Validate end_time is after start_time using resolved datetimes
                $startTimeInput = $this->input('start_time');
                $endTimeInput = $this->input('end_time');
                if ($startTimeInput && $endTimeInput) {
                    $startValid = preg_match('/^\d{2}:\d{2}:\d{2}$/', $startTimeInput);
                    $endValid = preg_match('/^\d{2}:\d{2}:\d{2}$/', $endTimeInput);
                    if ($startValid && $endValid) {
                        $shiftPlan = \App\Models\ShiftPlan::with('shift')->find($shiftPlanId);
                        if ($shiftPlan) {
                            $resolvedStart = \App\Services\DispatchTripService::resolveDateTimeFromTime($startTimeInput, $shiftPlan);
                            $resolvedEnd = \App\Services\DispatchTripService::resolveDateTimeFromTime($endTimeInput, $shiftPlan);
                            if (\Carbon\Carbon::parse($resolvedEnd)->lte(\Carbon\Carbon::parse($resolvedStart))) {
                                $validator->errors()->add('end_time', 'The end time must be after the start time.');
                            }
                        }
                    }
                }

                // (d) Validate loading_point_id and dumping_point_id belong to site_id and have correct types
                $siteId = $this->input('site_id');
                if ($siteId) {
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
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422)
        );
    }
}
