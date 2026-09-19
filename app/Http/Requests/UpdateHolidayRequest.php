<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesHolidayUniqueness;
use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateHolidayRequest extends FormRequest
{
    use ValidatesHolidayUniqueness;

    /** Memoised lookup of the row being edited. */
    protected $resolvedHoliday = [];

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

    /**
     * The date currently stored on the row, in the same Y-m-d shape
     * normaliseHolidayInput() puts the submitted value in, so the two can be
     * compared as strings. Resolved once - rules() and the validator both ask
     * for it, and only one query should go out.
     */
    protected function existingHolidayDate(): ?string
    {
        if (! array_key_exists('date', $this->resolvedHoliday)) {
            $holiday = ($id = $this->holidayId()) ? Holiday::find($id) : null;

            $this->resolvedHoliday['date'] = $holiday && $holiday->holiday_date
                ? $holiday->holiday_date->format('Y-m-d')
                : null;
        }

        return $this->resolvedHoliday['date'];
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
