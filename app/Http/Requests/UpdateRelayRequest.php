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
                            $rotatingRelays = \App\Models\Relay::where('id', '!=', $relayId)
                                ->where('is_active', 1)
                                ->where('is_rotating', true)
                                ->get();

                            foreach ($rotatingRelays as $relay) {
                                if ($relay->current_shift_id == $value) {
                                    $fail('The selected shift is already assigned to another rotating relay.');
                                    break;
                                }
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
