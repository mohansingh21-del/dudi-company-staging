<?php

namespace App\Http\Requests;

use App\Models\Training;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same shape as the store request, except that the type and supervisor
     * already saved on this training stay selectable even after they are
     * deactivated — otherwise a training could never be edited again once its
     * type was retired.
     */
    public function rules(): array
    {
        $training = Training::find($this->route('training') ?? $this->route('id'));

        return [
            'training_name' => 'required|string|max:255',

            'training_type_id' => [
                'required',
                Rule::exists('training_types', 'id')
                    ->where(function ($query) use ($training) {
                        $query->where('is_active', 1);

                        if ($training) {
                            $query->orWhere('id', $training->training_type_id);
                        }
                    }),
            ],

            'supervisor_id' => [
                'required',
                Rule::exists('employees', 'id')
                    ->where(function ($query) use ($training) {
                        $query->where('is_active', 1);

                        if ($training) {
                            $query->orWhere('id', $training->supervisor_id);
                        }
                    }),
            ],

            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',

            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => [
                'required',
                'distinct',
                Rule::exists('employees', 'id')->where('is_active', 1),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'training_type_id.exists' => 'The selected training type is not available.',
            'supervisor_id.exists' => 'The selected supervisor is not an active employee.',
            'employee_ids.required' => 'Enroll at least one employee.',
            'employee_ids.*.exists' => 'One or more selected employees are not active.',
            'employee_ids.*.distinct' => 'The same employee cannot be enrolled twice.',
        ];
    }

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
