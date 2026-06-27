<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Traits\ValidatesDateRange;

class FuelRegisterFilterRequest extends FormRequest
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
            'machine_type_id' => 'nullable|integer',
            'machine_number_id' => 'nullable|integer',
            'operator_id' => 'nullable|integer',
            'site_id' => 'nullable|integer',
            'fuel_source' => 'nullable|string|in:fuel_tanker,fuel_station,mobile_refueling_unit',
            'flag' => 'nullable|string|in:high_consumption,low_efficiency',
            'fuel_ref_no' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1',
        ];
    }
}
