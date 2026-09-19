<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesHolidayUniqueness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateHolidayRequest extends FormRequest
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

    /**
     * The route model is bound as `{holiday}` on the apiResource, and the
     * controller takes it as an int id.
     */
    protected function holidayId(): ?int
    {
        $id = $this->route('holiday');

        if (is_object($id)) {
            $id = $id->id ?? null;
        }

        return $id !== null ? (int) $id : null;
    }

    public function rules(): array
    {
        return $this->holidayRules($this->holidayId());
    }

    public function messages(): array
    {
        return $this->holidayMessages();
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $this->validateAgainstGeneralHoliday($validator, $this->holidayId());
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
