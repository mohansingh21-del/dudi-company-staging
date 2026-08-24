<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateRelayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $relayId = $this->route('relay');

        return [
            'name' => 'required|string|unique:relays,name,' . $relayId . '|max:255',
            'is_rotating' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'shift_id' => [
                'nullable',
                'exists:shifts,id',
                function ($attribute, $value, $fail) use ($relayId) {
                    if ($value) {
                        $isRotating = $this->input('is_rotating');
                        if (is_null($isRotating)) {
                            $relay = \App\Models\Relay::find($relayId);
                            $isRotating = $relay ? $relay->is_rotating : true;
                        }

                        if ($isRotating) {
                            $holder = \App\Models\RelayShiftMapping::getHolderOfShift(
                                $value,
                                now()->toDateString(),
                                $relayId
                            );

                            if ($holder) {
                                $name = $holder->relay ? $holder->relay->name : 'another relay';
                                $fail("The selected shift is already assigned to {$name} for this week.");
                            }
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
