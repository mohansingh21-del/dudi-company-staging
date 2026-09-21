<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateDelayRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        $delayId = $this->route('delay') ?: $this->route('id');
        if (is_object($delayId)) {
            $delay = $delayId;
        } else {
            $delay = \App\Models\Delay::find($delayId);
        }

        if ($delay && $delay->shift_plan_id) {
            $shiftPlan = \App\Models\ShiftPlan::find($delay->shift_plan_id);
            if ($shiftPlan && $shiftPlan->status === 'closed') {
                // Only super-admin role can edit closed shift delays
                return $this->user()->roles()->where('slug', 'super-admin')->exists();
            }
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $isMachineBreakdown = false;
        $delayCategoryId = $this->input('delay_category_id');
        if (!$delayCategoryId) {
            $delayId = $this->route('delay') ?: $this->route('id');
            if (is_object($delayId)) {
                $delay = $delayId;
            } else {
                $delay = \App\Models\Delay::find($delayId);
            }
            if ($delay) {
                $delayCategoryId = $delay->delay_category_id;
            }
        }

        if ($delayCategoryId) {
            $category = \App\Models\DelayCategory::find($delayCategoryId);
            if ($category && strtolower(str_replace([' ', '-'], '_', $category->delay_category)) === 'machine_breakdown') {
                $isMachineBreakdown = true;
            }
        }

        return [
            'delay_category_id' => 'sometimes|required|numeric|exists:delay_categories,id,is_active,1',
            'delay_subcategory' => 'nullable|string|max:255',
            'start_time' => 'sometimes|required|date_format:H:i:s',
            'end_time' => 'nullable|date_format:H:i:s|after_or_equal:start_time',
            'severity' => 'nullable|string|in:LOW,MEDIUM,HIGH,CRITICAL',
            'linked_breakdown_id' => 'nullable|exists:breakdown_tickets,id',
            'equipment_id' => ($isMachineBreakdown ? 'required' : 'nullable') . '|exists:equipments,id',
            'equipment_name_id' => ($isMachineBreakdown ? 'required' : 'nullable') . '|exists:equipment_names,id',
            'description' => 'sometimes|required|string',
            'remarks' => 'nullable|string',
            'delay_ref_no' => 'sometimes|string',
            'shift_plan_id' => 'sometimes|integer',
            'shift_id' => ['sometimes', 'integer', 'exists:shifts,id', new \App\Rules\ActiveShift($this->currentShiftId())],
            'delay_log_date' => 'sometimes|date',
            'shift_date' => 'sometimes|date_format:Y-m-d',
            'shift_name' => 'sometimes|string',
            'created_by' => 'sometimes|integer',
            'created_at' => 'sometimes',
        ];
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
            $delayId = $this->route('delay') ?: $this->route('id');
            if (is_object($delayId)) {
                $delay = $delayId;
            } else {
                $delay = \App\Models\Delay::find($delayId);
            }

            $shiftPlanId = $this->input('shift_plan_id') ?? ($delay ? $delay->shift_plan_id : null);
            $shiftId = $this->input('shift_id');
            $delayLogDateInput = $this->input('delay_log_date');

            if ($shiftPlanId && $shiftId) {
                $shiftPlan = \App\Models\ShiftPlan::find($shiftPlanId);
                if ($shiftPlan && $shiftPlan->shift_id != $shiftId) {
                    $validator->errors()->add('shift_id', 'The selected shift does not match the shift plan.');
                }
            }

            if ($shiftPlanId && $delayLogDateInput) {
                $shiftPlan = \App\Models\ShiftPlan::find($shiftPlanId);
                if ($shiftPlan) {
                    $delayLogDatePart = \Carbon\Carbon::parse($delayLogDateInput)->format('Y-m-d');
                    $shiftPlanDatePart = \Carbon\Carbon::parse($shiftPlan->planning_date)->format('Y-m-d');
                    if ($delayLogDatePart !== $shiftPlanDatePart) {
                        $validator->errors()->add('delay_log_date', 'The delay log date must match the planning date of the shift plan.');
                    }
                }
            }

            $finalBreakdownId = $this->has('linked_breakdown_id') ? $this->input('linked_breakdown_id') : ($delay ? $delay->linked_breakdown_id : null);
            if ($shiftPlanId && $finalBreakdownId) {
                $breakdown = \App\Models\BreakdownTicket::find($finalBreakdownId);
                if ($breakdown) {
                    if ($breakdown->equipment_allocation_id) {
                        $allocation = \App\Models\ShiftEquipmentAllocation::find($breakdown->equipment_allocation_id);
                        if (!$allocation || $allocation->shift_plan_id != $shiftPlanId) {
                            $validator->errors()->add('linked_breakdown_id', 'The selected breakdown ticket is not associated with this shift plan.');
                        }
                    } else {
                        $shiftPlan = \App\Models\ShiftPlan::find($shiftPlanId);
                        if ($shiftPlan && $breakdown->shift_id != $shiftPlan->shift_id) {
                            $validator->errors()->add('linked_breakdown_id', 'The selected breakdown ticket is not associated with this shift plan.');
                        }
                    }
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'delay_category_id.required' => 'Please Select Delay Category',
            'start_time.required' => 'Start Time is required',
            'severity.required' => 'Please Select Severity',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422)
        );
    }

    /**
     * The shift the record is saved on now, which may stay even if inactive.
     */
    private function currentShiftId()
    {
        $record = $this->route('delay') ?: $this->route('id');

        if (!is_object($record)) {
            $record = \App\Models\Delay::find($record);
        }

        return $record ? $record->shift_id : null;
    }
}
