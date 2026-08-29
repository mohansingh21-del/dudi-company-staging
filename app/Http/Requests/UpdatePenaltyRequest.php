<?php

namespace App\Http\Requests;

use App\Enums\RecoveryType;
use App\Rules\HasActivePayroll;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdatePenaltyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => [
                'sometimes',
                'required',
                'exists:employees,id',
                new HasActivePayroll(),
            ],

            'penalty_date' => [
                'sometimes',
                'required',
                'date',
            ],

            'recovery_type' => [
                'sometimes',
                'required',
                Rule::in(RecoveryType::values()),
            ],

            'reason' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'particulars' => [
                'sometimes',
                'required',
                'string',
                'min:1',
            ],

            'amount' => [
                'sometimes',
                'required',
                'numeric',
                'min:0.01',
            ],

            'show_cause_issued' => [
                'sometimes',
                'required',
                'boolean',
            ],

            'explanation_heard_in_presence' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'number_of_installments' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            'first_month' => [
                'sometimes',
                'nullable',
                'integer',
                'between:1,12',
            ],

            'first_year' => [
                'sometimes',
                'nullable',
                'integer',
                'between:1900,2200',
            ],

            'last_month' => [
                'sometimes',
                'nullable',
                'integer',
                'between:1,12',
            ],

            'last_year' => [
                'sometimes',
                'nullable',
                'integer',
                'between:1900,2200',
            ],

            'date_of_complete_recovery' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'remarks' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
