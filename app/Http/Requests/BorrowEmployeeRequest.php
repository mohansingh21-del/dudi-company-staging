<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class BorrowEmployeeRequest extends FormRequest
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
            'employee_ids'        => 'required|array|min:1',
            'employee_ids.*'      => 'integer|exists:employees,id',
            'borrowing_reason'    => 'required|string|max:255',
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
            'employee_ids.required'      => 'Please select at least one employee.',
            'employee_ids.array'         => 'Employee selection must be a list.',
            'employee_ids.min'           => 'Please select at least one employee.',
            'employee_ids.*.integer'     => 'Invalid employee selection.',
            'employee_ids.*.exists'      => 'One or more selected employees do not exist.',
            'borrowing_reason.required'  => 'Please provide a borrowing reason.',
            'borrowing_reason.max'       => 'Borrowing reason must not exceed 255 characters.',
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
        $errors  = $validator->errors();
        $message = $errors->first();

        throw new HttpResponseException(
            response()->json([
                'status'  => 422,
                'message' => $message,
                'data'    => $errors->toArray(),
            ], 422)
        );
    }
}
