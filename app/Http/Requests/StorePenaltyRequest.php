<?php

namespace App\Http\Requests;

use App\Enums\RecoveryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Rules\HasActivePayroll;
use Illuminate\Validation\Rule;

class StorePenaltyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => [
                'required',
                'exists:employees,id',
                new HasActivePayroll(),
            ],

            'penalty_date' => [
                'required',
                'date',
            ],

            'recovery_type' => [
                'required',
                Rule::in(RecoveryType::values()),
            ],

            'reason' => [
                'nullable',
                'string',
                'min:1',
            ],

            'particulars' => [
                'required',
                'string',
                'min:1',
            ],

            'amount' => [
                'required',
                'numeric',
                'min:0.01',
            ],

            'show_cause_issued' => [
                'required',
                'boolean',
            ],

            'explanation_heard_in_presence' => [
                'nullable',
                'string',
                'max:255',
            ],

            'number_of_installments' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'first_month' => [
                'nullable',
                'integer',
                'between:1,12',
            ],

            'first_year' => [
                'nullable',
                'integer',
                'between:1900,2200',
            ],

            'last_month' => [
                'nullable',
                'integer',
                'between:1,12',
            ],

            'last_year' => [
                'nullable',
                'integer',
                'between:1900,2200',
            ],

            'date_of_complete_recovery' => [
                'nullable',
                'date',
            ],

            'remarks' => [
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
