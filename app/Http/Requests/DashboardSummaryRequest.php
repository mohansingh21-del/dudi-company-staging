<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class DashboardSummaryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'date_range' => 'nullable|string|in:today,yesterday,current_shift,last_7_days,last_30_days,this_month,custom',
            'date_from' => 'required_if:date_range,custom|nullable|date_format:Y-m-d',
            'date_to' => 'required_if:date_range,custom|nullable|date_format:Y-m-d|after_or_equal:date_from',
            'mine_site_id' => 'nullable|integer|exists:sites,id',
            'block_id' => 'nullable|integer',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'machine_type_id' => 'nullable|integer|exists:equipments,id',
            'machine_number_id' => 'nullable|integer|exists:equipment_names,id',
            'delay_category_id' => 'nullable|integer|exists:delay_categories,id',
            'delay_severity' => 'nullable|string',
            'store_id' => 'nullable|integer|exists:stores,id',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation()
    {
        if (!$this->has('date_range')) {
            if ($this->has('date_from') && $this->has('date_to')) {
                $this->merge(['date_range' => 'custom']);
            } else {
                $this->merge(['date_range' => 'today']);
            }
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->input('date_range') === 'custom') {
                $from = $this->input('date_from');
                $to = $this->input('date_to');
                if ($from && $to) {
                    $fromDate = Carbon::parse($from);
                    $toDate = Carbon::parse($to);
                    if ($fromDate->diffInDays($toDate) > 366) {
                        $validator->errors()->add('date_to', 'The custom range cannot exceed 1 year.');
                    }
                }
            }
        });
    }

    /**
     * Resolve date range and site ID into concrete filter variables.
     *
     * @return array
     */
    public function getResolvedFilters()
    {
        $dateRange = $this->input('date_range', 'today');
        $from = null;
        $to = null;
        $shiftId = $this->input('shift_id') ? (int) $this->input('shift_id') : null;

        $today = Carbon::today();

        switch ($dateRange) {
            case 'today':
                $from = $today->toDateString();
                $to = $today->toDateString();
                break;
            case 'yesterday':
                $yesterday = Carbon::yesterday();
                $from = $yesterday->toDateString();
                $to = $yesterday->toDateString();
                break;
            case 'current_shift':
                $from = $today->toDateString();
                $to = $today->toDateString();
                if (empty($shiftId)) {
                    $currentTime = Carbon::now()->toTimeString();
                    $activeShift = \App\Models\Shift::where('is_active', 1)
                        ->where(function ($query) use ($currentTime) {
                            $query->where(function ($q) use ($currentTime) {
                                $q->where('start_time', '<=', $currentTime)
                                  ->where('end_time', '>=', $currentTime);
                            })->orWhere(function ($q) use ($currentTime) {
                                $q->whereColumn('start_time', '>', 'end_time')
                                  ->where(function ($sub) use ($currentTime) {
                                      $sub->where('start_time', '<=', $currentTime)
                                          ->orWhere('end_time', '>=', $currentTime);
                                  });
                            });
                        })->first();

                    if ($activeShift) {
                        $shiftId = $activeShift->id;
                    }
                }
                break;
            case 'last_7_days':
                $from = $today->copy()->subDays(6)->toDateString();
                $to = $today->toDateString();
                break;
            case 'last_30_days':
                $from = $today->copy()->subDays(29)->toDateString();
                $to = $today->toDateString();
                break;
            case 'this_month':
                $from = $today->copy()->startOfMonth()->toDateString();
                $to = $today->copy()->endOfMonth()->toDateString();
                break;
            case 'custom':
                $from = $this->input('date_from');
                $to = $this->input('date_to');
                break;
        }

        // Resolve site_id (mine_site_id)
        $mineSiteId = $this->input('mine_site_id');
        if (empty($mineSiteId) && auth()->user()) {
            $employee = auth()->user()->employee;
            if ($employee) {
                $mineSiteId = $employee->site_id;
            }
        }

        return [
            'date_range'        => $dateRange,
            'from'              => $from,
            'to'                => $to,
            'mine_site_id'      => $mineSiteId ? (int) $mineSiteId : null,
            'block_id'          => $this->input('block_id') ? (int) $this->input('block_id') : null,
            'shift_id'          => $shiftId,
            'machine_type_id'   => $this->input('machine_type_id') ? (int) $this->input('machine_type_id') : null,
            'machine_number_id' => $this->input('machine_number_id') ? (int) $this->input('machine_number_id') : null,
            'delay_category_id' => $this->input('delay_category_id') ? (int) $this->input('delay_category_id') : null,
            'delay_severity'    => $this->input('delay_severity'),
            'store_id'          => $this->input('store_id') ? (int) $this->input('store_id') : null,
        ];
    }
}
