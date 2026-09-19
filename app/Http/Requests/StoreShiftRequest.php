<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:shifts,shift_name',
            'start_time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'end_time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'minimum_working_hours' => 'nullable|numeric|min:0|max:24',
            'is_night_shift' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {

            if ($validator->errors()->hasAny(['start_time', 'end_time', 'minimum_working_hours'])) {
                return;
            }

            $start = $this->input('start_time');
            $end = $this->input('end_time');
            $minHours = $this->input('minimum_working_hours');

            if ($minHours === null || $minHours === '' || !$start || !$end) {
                return;
            }

            $durationHours = $this->shiftDurationInHours($start, $end);

            if ((float) $minHours > $durationHours) {
                $validator->errors()->add(
                    'minimum_working_hours',
                    'Minimum working hours cannot exceed the shift duration of ' . rtrim(rtrim(number_format($durationHours, 2, '.', ''), '0'), '.') . ' hours.'
                );
            }
        });
    }

    /**
     * Shift length in hours. An end time that is not after the start time rolls
     * over to the next day; identical times mean a full 24 hour shift.
     */
    private function shiftDurationInHours($start, $end)
    {
        $toSeconds = function ($time) {
            $parts = array_map('intval', explode(':', $time));
            return ($parts[0] * 3600) + (isset($parts[1]) ? $parts[1] * 60 : 0) + (isset($parts[2]) ? $parts[2] : 0);
        };

        $startSeconds = $toSeconds($start);
        $endSeconds = $toSeconds($end);

        $seconds = $endSeconds > $startSeconds
            ? $endSeconds - $startSeconds
            : ($endSeconds + 86400) - $startSeconds;

        return round($seconds / 3600, 2);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Shift name is required.',
            'name.string' => 'Shift name must be a valid text value.',
            'name.max' => 'Shift name may not be greater than 255 characters.',
            'name.unique' => 'Shift name already exists.',
            'start_time.required' => 'Start time is required.',
            'start_time.regex' => 'Start time must be in HH:MM or HH:MM:SS format.',
            'end_time.required' => 'End time is required.',
            'end_time.regex' => 'End time must be in HH:MM or HH:MM:SS format.',
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
