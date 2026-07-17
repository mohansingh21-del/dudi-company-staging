<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreRelayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|unique:relays,name|max:255',
            'is_rotating' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'shift_id' => [
                'nullable',
                'exists:shifts,id',
                function ($attribute, $value, $fail) {
                    if ($value && $this->input('is_rotating', true)) {
                        $today = now();
                        $weekStart = $today->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();

                        $exists = \App\Models\RelayShiftMapping::where('week_start_date', $weekStart)
                            ->where('shift_id', $value)
                            ->whereHas('relay', function ($q) {
                                $q->where('is_rotating', true);
                            })
                            ->exists();

                        if ($exists) {
                            $fail('The selected shift is already assigned to another rotating relay for this week.');
                        }
                    }
                }
            ],
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
