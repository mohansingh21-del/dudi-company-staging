<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AttendanceProcessed;

$planningDate = '2026-07-04';
$attendances = AttendanceProcessed::whereDate('date', $planningDate)->get();
echo "Attendance records count on $planningDate: " . $attendances->count() . "\n";
foreach ($attendances as $att) {
    echo "ID: " . $att->id . ", Employee ID: " . $att->employee_id . ", Status: " . $att->attendance_status . "\n";
}
