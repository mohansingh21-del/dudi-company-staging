<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use App\Models\ShiftPlan;

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
     * Accept the old single supervisor_id (or a comma-separated string) as
     * supervisor_ids so existing clients keep working.
     *
     * @return void
     */
    protected function prepareForValidation()
    {
        $ids = $this->input('supervisor_ids');

        if (is_null($ids) && $this->filled('supervisor_id')) {
            $ids = $this->input('supervisor_id');
        }

        if (!is_null($ids)) {
            $this->merge(['supervisor_ids' => $this->normalizeSupervisorIds($ids)]);
        }
    }

    /**
     * Turn [3, "4"], "3,4", "[3,4]", '["3","4"]' or [{"id":3}] into [3, 4].
     * Anything that isn't a whole number is left as is so validation flags it.
     *
     * @param  mixed  $ids
     * @return array
     */
    protected function normalizeSupervisorIds($ids)
    {
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded)
                ? $decoded
                : explode(',', trim($ids, " []"));
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $normalized = [];
        foreach ($ids as $id) {
            if (is_array($id)) {
                $id = $id['id'] ?? $id['employee_id'] ?? $id['value'] ?? null;
            }
            if (is_string($id)) {
                $id = trim($id, " \"'");
            }
            if (is_null($id) || $id === '') {
                continue;
            }
            $normalized[] = ctype_digit((string) $id) ? (int) $id : $id;
        }

        return $normalized;
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
            'shift_id' => ['sometimes', 'required', 'numeric', 'exists:shifts,id', new \App\Rules\ActiveShift(optional(\App\Models\ShiftPlan::find($id))->shift_id)],
            'site_id' => 'sometimes|required|numeric|exists:sites,id',
            'target_bcm' => 'sometimes|required|numeric|gt:0|max:' . ShiftPlan::MAX_BCM,
            'supervisor_ids' => 'sometimes|required|array|min:1|max:' . ShiftPlan::MAX_SUPERVISORS,
            'supervisor_ids.*' => 'required|integer|distinct|exists:employees,id',
            'site_incharge_id' => 'sometimes|required|exists:employees,id',
            'status' => 'sometimes|nullable|in:draft,published,in_progress,planned,active,closed',
            'actual_bcm' => 'sometimes|nullable|numeric|min:0|max:' . ShiftPlan::MAX_BCM,
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
            'target_bcm.gt' => 'Target BCM must be greater than 0.',
            'target_bcm.max' => 'Target BCM may not be greater than 99,999,999.99.',
            'actual_bcm.max' => 'Actual BCM may not be greater than 99,999,999.99.',
            'supervisor_ids.required' => 'At least one supervisor is required.',
            'supervisor_ids.array' => 'Supervisors must be a list.',
            'supervisor_ids.min' => 'At least one supervisor is required.',
            'supervisor_ids.max' => 'A shift plan can have at most ' . ShiftPlan::MAX_SUPERVISORS . ' supervisors.',
            'supervisor_ids.*.distinct' => 'The same supervisor is selected more than once.',
            'supervisor_ids.*.exists' => 'Selected supervisor is invalid.',
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
