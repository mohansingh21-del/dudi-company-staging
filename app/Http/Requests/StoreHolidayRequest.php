<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesHolidayUniqueness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreHolidayRequest extends FormRequest
{
    use ValidatesHolidayUniqueness;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $this->normaliseHolidayInput();
    }

    public function rules(): array
    {
        return $this->holidayRules();
    }

    public function messages(): array
    {
        return $this->holidayMessages();
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $this->validateAgainstGeneralHoliday($validator);
        });
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
