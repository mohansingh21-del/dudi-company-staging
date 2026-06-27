<?php

namespace App\Http\Requests\Traits;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

trait ValidatesDateRange
{
    /**
     * Handle failed validation and return envelope.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status'  => 422,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422)
        );
    }

    /**
     * Configure the validator instance to validate date_from <= date_to.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $dateFrom = $this->input('date_from');
            $dateTo = $this->input('date_to');

            if ($dateFrom && $dateTo) {
                try {
                    $from = \Carbon\Carbon::parse($dateFrom);
                    $to = \Carbon\Carbon::parse($dateTo);
                    if ($from->gt($to)) {
                        $validator->errors()->add('date_from', 'Invalid Date Range Selected');
                    }
                } catch (\Exception $e) {
                    $validator->errors()->add('date_from', 'The date format is invalid.');
                }
            }
        });
    }
}
