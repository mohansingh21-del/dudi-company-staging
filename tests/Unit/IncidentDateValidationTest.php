<?php

namespace Tests\Unit;

use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\UpdateIncidentRequest;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class IncidentDateValidationTest extends TestCase
{
    public function test_store_request_treats_today_as_not_future_when_date_is_date_only(): void
    {
        $request = new class extends StoreIncidentRequest {
            public function exposedIsFutureIncidentDate($value): bool
            {
                return $this->isFutureIncidentDate($value);
            }
        };

        $this->assertFalse($request->exposedIsFutureIncidentDate(Carbon::today()->toDateString()));
    }

    public function test_update_request_treats_today_as_not_future_when_date_is_date_only(): void
    {
        $request = new class extends UpdateIncidentRequest {
            public function exposedIsFutureIncidentDate($value): bool
            {
                return $this->isFutureIncidentDate($value);
            }
        };

        $this->assertFalse($request->exposedIsFutureIncidentDate(Carbon::today()->toDateString()));
    }
}
