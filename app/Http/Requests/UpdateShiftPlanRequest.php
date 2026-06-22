<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateShiftPlanRequest extends FormRequest
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
        $id = $this->route('shift_plan') ?: $this->route('id');
        if (is_object($id)) {
            $id = $id->id;
        }

        return [
            'planning_date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                Rule::unique('shift_plans')->where(function ($query) {
                    $shiftId = $this->shift_id ?: $this->getCurrentShiftPlanValue('shift_id');
                    $siteId = $this->site_id ?: $this->getCurrentShiftPlanValue('site_id');
                    return $query->where('shift_id', $shiftId)
                        ->where('site_id', $siteId);
                })->ignore($id),
            ],
            'shift_id' => 'sometimes|required|numeric|exists:shifts,id',
            'site_id' => 'sometimes|required|numeric|exists:sites,id',
            'target_bcm' => 'sometimes|required|numeric|gt:0',
            'supervisor_id' => 'sometimes|required|exists:employees,id',
            'site_incharge_id' => 'sometimes|required|exists:employees,id',
            'status' => 'sometimes|nullable|in:draft,planned,active,closed',
            'actual_bcm' => 'sometimes|nullable|numeric|min:0',
        ];
    }

    /**
     * Helper to get the existing database value for shift plan.
     *
     * @param string $column
     * @return mixed
     */
    protected function getCurrentShiftPlanValue($column)
    {
        $id = $this->route('shift_plan') ?: $this->route('id');
        if (is_object($id)) {
            return $id->$column;
        }

        $shiftPlan = \App\Models\ShiftPlan::find($id);
        return $shiftPlan ? $shiftPlan->$column : null;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'planning_date.unique' => 'A shift plan already exists for this date, shift and site.',
            'planning_date.required' => 'Planning date is required.',
            'planning_date.date_format' => 'Planning date must be in Y-m-d format.',
            'shift_id.required' => 'Shift is required.',
            'shift_id.exists' => 'Selected shift is invalid.',
            'site_id.required' => 'Site is required.',
            'site_id.exists' => 'Selected site is invalid.',
            'target_bcm.required' => 'Target BCM is required.',
            'target_bcm.numeric' => 'Target BCM must be a number.',
            'supervisor_id.required' => 'Supervisor is required.',
            'supervisor_id.exists' => 'Selected supervisor is invalid.',
            'site_incharge_id.required' => 'Site incharge is required.',
            'site_incharge_id.exists' => 'Selected site incharge is invalid.',
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
