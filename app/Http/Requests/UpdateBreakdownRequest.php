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
            'status'           => 'nullable|in:open,in_progress,on_hold,closed',
            'severity'         => 'nullable|string|in:LOW,MEDIUM,HIGH,CRITICAL',
            'description'      => 'nullable|string|max:1000',
            'downtime_end'     => 'required_if:status,closed|date_format:Y-m-d H:i:s',
            'resolution_notes' => 'required_if:status,closed|string|max:1000',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->input('status') === 'closed') {
                $ticketId = $this->route('id');
                $ticket = \App\Models\BreakdownTicket::find($ticketId);

                if (!$ticket) {
                    return;
                }

                $downtimeEnd = $this->input('downtime_end');
                if ($downtimeEnd) {
                    $end = \Carbon\Carbon::parse($downtimeEnd);
                    $start = \Carbon\Carbon::parse($ticket->downtime_start);
                    if ($end->lte($start)) {
                        $validator->errors()->add('downtime_end', 'The downtime end must be a date after downtime start.');
                    }
                }
            }
        });
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
