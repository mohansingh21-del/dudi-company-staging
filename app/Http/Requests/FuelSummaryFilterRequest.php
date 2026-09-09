<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\ValidatesDateRange;

class FuelSummaryFilterRequest extends FormRequest
{
    use ValidatesDateRange;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'date_from' => 'nullable|string',
            'date_to' => 'nullable|string',
            'period' => 'nullable|string',
            'shift_id' => 'nullable|integer',
            'machine_number_id' => 'nullable|integer',
            'site_id' => 'nullable|integer',
        ];
    }
}
