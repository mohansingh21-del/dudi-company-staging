<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreDelayRequest extends FormRequest
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
        $isMachineBreakdown = false;
        $delayCategoryId = $this->input('delay_category_id');
        if ($delayCategoryId) {
            $category = \App\Models\DelayCategory::find($delayCategoryId);
            if ($category && strtolower(str_replace([' ', '-'], '_', $category->delay_category)) === 'machine_breakdown') {
                $isMachineBreakdown = true;
            }
        }

        return [
            'shift_plan_id' => 'required|numeric|exists:shift_plans,id',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'delay_log_date' => 'nullable|date',
            'delay_category_id' => 'required|numeric|exists:delay_categories,id,is_active,1',
            'delay_subcategory' => 'nullable|string|max:255',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'nullable|date_format:H:i:s|after_or_equal:start_time',
            'severity' => 'nullable|string|in:LOW,MEDIUM,HIGH,CRITICAL',
            'linked_breakdown_id' => 'nullable|exists:breakdown_tickets,id',
            'equipment_id' => ($isMachineBreakdown ? 'required' : 'nullable') . '|exists:equipments,id',
            'equipment_name_id' => ($isMachineBreakdown ? 'required' : 'nullable') . '|exists:equipment_names,id',
            'description' => 'required|string',
            'remarks' => 'nullable|string',
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
            $shiftPlanId = $this->input('shift_plan_id');
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

            $linkedBreakdownId = $this->input('linked_breakdown_id');
            if ($shiftPlanId && $linkedBreakdownId) {
                $breakdown = \App\Models\BreakdownTicket::find($linkedBreakdownId);
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
            'shift_plan_id.required' => 'Please Select Shift',
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
}
