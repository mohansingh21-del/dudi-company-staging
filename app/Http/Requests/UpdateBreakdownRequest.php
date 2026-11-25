<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateBreakdownRequest extends FormRequest
{
    public function authorize()
    {
        $ticketId = $this->route('id');
        $ticket = \App\Models\BreakdownTicket::find($ticketId);

        if ($ticket && $ticket->status === 'closed') {
            throw new HttpResponseException(
                response()->json([
                    'status'  => 403,
                    'message' => 'Closed incidents cannot be edited.',
                    'data'    => null
                ], 403)
            );
        }

        return true;
    }

    public function rules()
    {
        return [
            // 'closed' is deliberately absent: a ticket closes only when a linked
            // service record records its downtime window, which guarantees every
            // closed ticket carries real downtime for the MTTR dashboards.
            'status'              => 'nullable|in:open,in_progress,on_hold',
            'breakdown_date_time' => 'nullable|date_format:Y-m-d H:i:s',
            'severity'            => 'nullable|string|in:LOW,MEDIUM,HIGH,CRITICAL',
            'description'         => 'nullable|string|max:1000',
            'resolution_notes'    => 'nullable|string|max:1000',
        ];
    }

    public function messages()
    {
        return [
            'status.in' => 'A breakdown ticket is closed by completing its service record with a downtime window, not directly.',
        ];
    }

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
}
