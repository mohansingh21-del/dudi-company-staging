<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\ValidatesDateRange;

class FuelPerformanceFilterRequest extends FormRequest
{
    use ValidatesDateRange;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'range' => 'nullable|string|in:today,yesterday,current_shift,custom',
            'date_from' => 'required_if:range,custom|nullable|string',
            'date_to' => 'required_if:range,custom|nullable|string',
        ];
    }
}
