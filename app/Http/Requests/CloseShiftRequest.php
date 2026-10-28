<?php

namespace App\Http\Requests;

use App\Services\ShiftClosureService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CloseShiftRequest extends FormRequest
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
     * Merge has_active_breakdown flag into request data before validation.
     * PHP 7.4 compatible — uses ternary instead of nullsafe operator.
     *
     * @return void
     */
    protected function prepareForValidation()
    {
        $shift = $this->route('shift_plan');
        $hasActiveBreakdown = false;

        if ($shift) {
            $service = app(ShiftClosureService::class);
            $hasActiveBreakdown = $service->hasActiveBreakdown($shift);
        }

        $this->merge([
            'has_active_breakdown' => $hasActiveBreakdown ? 'true' : 'false',
        ]);

        // Form-data sends "true"/"false"/"yes"/"on"; the boolean rule only takes 1/0.
        if ($this->has('fuel_logs_available')) {
            $value = $this->input('fuel_logs_available');
            $bool = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            $this->merge([
                'fuel_logs_available' => is_null($bool) ? $value : $bool,
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'supervisor_remarks' => ['required', 'string', 'min:20'],
            'handover_notes' => ['nullable', 'string'],
            'closure_confirmed' => ['required', 'accepted'],
            'breakdown_justification' => ['nullable', 'string'],
            'attendance_submitted' => ['required', 'accepted'],
            // Fuel issue entry is optional at closure.
            'fuel_logs_available' => ['nullable', 'boolean'],
            'delay_logs_updated' => ['required', 'accepted'],
            'breakdown_logs_updated' => ['required', 'accepted'],
            'production_data_available' => ['required', 'accepted'],
            'safety_data_reviewed' => ['required', 'accepted'],
        ];
    }

    /**
     * Custom error messages.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'supervisor_remarks.required' => 'Please Enter Supervisor Remarks.',
            'supervisor_remarks.min' => 'Supervisor remarks must be at least 20 characters.',
            'closure_confirmed.required' => 'Please Confirm Operational Review.',
            'closure_confirmed.accepted' => 'Please Confirm Operational Review.',
            'attendance_submitted.required' => 'Please Verify Attendance Submitted.',
            'attendance_submitted.accepted' => 'Please Verify Attendance Submitted.',
            'fuel_logs_available.boolean' => 'Fuel logs available must be true or false.',
            'delay_logs_updated.required' => 'Please Verify Delay Logs Updated.',
            'delay_logs_updated.accepted' => 'Please Verify Delay Logs Updated.',
            'breakdown_logs_updated.required' => 'Please Verify Breakdown Logs Updated.',
            'breakdown_logs_updated.accepted' => 'Please Verify Breakdown Logs Updated.',
            'production_data_available.required' => 'Please Verify Production Data Available.',
            'production_data_available.accepted' => 'Please Verify Production Data Available.',
            'safety_data_reviewed.required' => 'Please Verify Safety Data Reviewed.',
            'safety_data_reviewed.accepted' => 'Please Verify Safety Data Reviewed.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     * Returns {status, message, data} envelope matching codebase convention.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 422,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
                'data' => null,
            ], 422)
        );
    }
}
