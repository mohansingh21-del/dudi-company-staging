<?php

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftHistory;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkingDay;
use Illuminate\Support\Facades\Schema;

// Bootstrap Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Run the monthly roster test setup manually in a transaction
DB::beginTransaction();

try {
    $site = Site::create([
        'site_name' => 'Saran Coal Mine',
        'is_active' => 1
    ]);

    $shiftA = Shift::create([
        'shift_name' => 'Morning Shift',
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'minimum_working_hours' => 8.00,
        'is_night_shift' => 0,
        'is_active' => 1
    ]);

    $shiftB = Shift::create([
        'shift_name' => 'Night Shift',
        'start_time' => '20:00:00',
        'end_time' => '04:00:00',
        'minimum_working_hours' => 8.00,
        'is_night_shift' => 1,
        'is_active' => 1
    ]);

    $employee = Employee::create([
        'employee_code' => 'EMP100',
        'name' => 'Neha Jain',
        'joining_date' => '2026-01-01',
        'site_id' => $site->id,
        'is_active' => 1
    ]);

    WorkingDay::query()->delete();
    WorkingDay::create(['day' => 'Monday', 'is_working' => 1]);
    WorkingDay::create(['day' => 'Tuesday', 'is_working' => 1]);
    WorkingDay::create(['day' => 'Wednesday', 'is_working' => 1]);
    WorkingDay::create(['day' => 'Thursday', 'is_working' => 1]);
    WorkingDay::create(['day' => 'Friday', 'is_working' => 1]);
    WorkingDay::create(['day' => 'Saturday', 'is_working' => 0]);
    WorkingDay::create(['day' => 'Sunday', 'is_working' => 0]);

    // Setup assignment like test_resolves_historical_shifts
    $assignment = EmployeeShiftAssignment::create([
        'employee_id' => $employee->id,
        'shift_id' => $shiftA->id,
        'from_date' => '2026-05-01',
    ]);

    $assignment->update([
        'shift_id' => $shiftB->id,
        'from_date' => '2026-05-15',
    ]);

    // Call the controller method
    $request = new \Illuminate\Http\Request([
        'employee_id' => $employee->id,
        'month' => '2026-05'
    ]);

    $controller = new \App\Http\Controllers\Api\Admin\ShiftChangeController();
    $response = $controller->monthlyRoster($request);

    echo "Response status: " . $response->status() . "\n";
    echo "Response content: " . $response->content() . "\n";

} catch (\Throwable $e) {
    echo "Caught Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    DB::rollBack();
}
