<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use App\Models\ShiftPlan;

class StoreShiftPlanRequest extends FormRequest
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
            'planning_date' => [
                'required',
                'date_format:Y-m-d',
                Rule::unique('shift_plans')->where(function ($query) {
                    return $query->where('shift_id', $this->shift_id)
                        ->where('site_id', $this->site_id);
                }),
            ],
            'shift_id' => ['required', 'exists:shifts,id', new \App\Rules\ActiveShift()],
            'site_id' => 'required|exists:sites,id',
            'target_bcm' => 'required|numeric|gt:0|max:' . ShiftPlan::MAX_BCM,
            'supervisor_id' => 'required|exists:employees,id',
            'site_incharge_id' => 'required|exists:employees,id',
            'status' => 'nullable|in:draft,published,in_progress,planned,active,closed',
            'actual_bcm' => 'nullable|numeric|min:0|max:' . ShiftPlan::MAX_BCM,
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
            'planning_date.unique' => 'A shift plan already exists for this date, shift and site.',
            'planning_date.required' => 'Planning date is required.',
            'planning_date.date_format' => 'Planning date must be in Y-m-d format.',
            'shift_id.required' => 'Shift is required.',
            'shift_id.exists' => 'Selected shift is invalid.',
            'site_id.required' => 'Site is required.',
            'site_id.exists' => 'Selected site is invalid.',
            'target_bcm.required' => 'Target BCM is required.',
            'target_bcm.numeric' => 'Target BCM must be a number.',
            'target_bcm.gt' => 'Target BCM must be greater than 0.',
            'target_bcm.max' => 'Target BCM may not be greater than 99,999,999.99.',
            'actual_bcm.max' => 'Actual BCM may not be greater than 99,999,999.99.',
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
