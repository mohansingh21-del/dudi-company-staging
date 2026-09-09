<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use App\Models\User;
use App\Models\RoleUser;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Shift;
use App\Models\SitePoint;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\ShiftPlan;
use App\Models\ShiftEquipmentAllocation;
use App\Models\FuelEntry;
use App\Models\DispatchTrip;
use App\Models\BreakdownTicket;
use App\Models\BreakdownType;
use App\Models\Delay;
use App\Models\DelayCategory;
use Carbon\Carbon;

class DummyDashboardSeeder extends Seeder
{
    /**
     * Seed realistic data for the last 7 days.
     *
     * @return void
     */
    public function run()
    {
        DB::transaction(function () {
            // 1. Roles
            $superAdminRole = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'System-Administrator', 'is_active' => 1]);
            $supervisorRole = Role::firstOrCreate(['slug' => 'supervisor'], ['name' => 'Supervisor', 'is_active' => 1]);
            $driverRole = Role::firstOrCreate(['slug' => 'driver'], ['name' => 'Driver', 'is_active' => 1]);
            $workerRole = Role::firstOrCreate(['slug' => 'worker'], ['name' => 'Worker', 'is_active' => 1]);

            // 2. Users
            $adminUser = User::firstOrCreate(['email' => 'admin@test.com'], [
                'password' => bcrypt('password'),
                'is_active' => true,
            ]);
            RoleUser::firstOrCreate([
                'user_id' => $adminUser->id,
                'role_id' => $superAdminRole->id,
            ]);

            $supervisorUser = User::firstOrCreate(['email' => 'supervisor@test.com'], [
                'password' => bcrypt('password'),
                'is_active' => true,
            ]);
            $ruSup = RoleUser::firstOrCreate([
                'user_id' => $supervisorUser->id,
                'role_id' => $supervisorRole->id,
            ]);

            // 3. Employees
            $supervisorEmp = Employee::updateOrCreate(['employee_code' => 'EMP-SUP'], [
                'name' => 'Shift Supervisor',
                'joining_date' => '2026-01-01',
                'is_active' => true,
                'designation_id' => $supervisorRole->id,
                'role_user_id' => $ruSup->id,
            ]);

            // Drivers
            $drivers = [];
            for ($i = 1; $i <= 5; $i++) {
                $driverUser = User::firstOrCreate(['email' => "driver{$i}@test.com"], [
                    'password' => bcrypt('password'),
                    'is_active' => true,
                ]);
                $ru = RoleUser::firstOrCreate([
                    'user_id' => $driverUser->id,
                    'role_id' => $driverRole->id,
                ]);
                $drivers[$i] = Employee::updateOrCreate(['employee_code' => "EMP-D{$i}"], [
                    'name' => "Driver {$i}",
                    'joining_date' => '2026-01-01',
                    'is_active' => true,
                    'designation_id' => $driverRole->id,
                    'role_user_id' => $ru->id,
                ]);
            }

            // Operators
            $operators = [];
            for ($i = 1; $i <= 3; $i++) {
                $opUser = User::firstOrCreate(['email' => "operator{$i}@test.com"], [
                    'password' => bcrypt('password'),
                    'is_active' => true,
                ]);
                $ru = RoleUser::firstOrCreate([
                    'user_id' => $opUser->id,
                    'role_id' => $workerRole->id,
                ]);
                $operators[$i] = Employee::updateOrCreate(['employee_code' => "EMP-O{$i}"], [
                    'name' => "Operator {$i}",
                    'joining_date' => '2026-01-01',
                    'is_active' => true,
                    'designation_id' => $workerRole->id,
                    'role_user_id' => $ru->id,
                ]);
            }

            // 4. Sites
            $site = Site::firstOrCreate(['site_name' => 'Site Alpha'], [
                'is_active' => true,
            ]);

            // Link admin user to site (so dashboard queries filter correctly)
            $adminEmp = Employee::where('role_user_id', RoleUser::where('user_id', $adminUser->id)->first()->id)->first();
            if ($adminEmp) {
                $adminEmp->update(['site_id' => $site->id]);
            }

            // 5. Shifts
            $shift = Shift::firstOrCreate(['shift_name' => 'Day Shift'], [
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
                'is_night_shift' => 0,
                'minimum_working_hours' => 8,
            ]);

            // 6. Site Points
            $loadingPoint = SitePoint::firstOrCreate([
                'site_id' => $site->id,
                'name' => 'LP-1',
                'type' => 'loading',
            ], [
                'is_active' => true,
                'created_by' => $adminUser->id,
            ]);

            $dumpingPoint = SitePoint::firstOrCreate([
                'site_id' => $site->id,
                'name' => 'DP-1',
                'type' => 'dumping',
            ], [
                'is_active' => true,
                'created_by' => $adminUser->id,
            ]);

            // 7. Equipment Categories & Instances
            $excavatorCat = Equipment::firstOrCreate(['name' => 'Excavator'], ['is_active' => true]);
            $dumperCat = Equipment::firstOrCreate(['name' => 'Dumper'], ['is_active' => true]);

            $exc1Name = EquipmentName::firstOrCreate(['equipment_name' => 'EXC-01'], ['equipment_id' => $excavatorCat->id, 'is_active' => true]);
            $exc2Name = EquipmentName::firstOrCreate(['equipment_name' => 'EXC-02'], ['equipment_id' => $excavatorCat->id, 'is_active' => true]);

            $dmpNames = [];
            for ($i = 1; $i <= 5; $i++) {
                $dmpNames[$i] = EquipmentName::firstOrCreate(['equipment_name' => "DMP-0{$i}"], ['equipment_id' => $dumperCat->id, 'is_active' => true]);
            }

            // Delay Categories
            $mealDelayCat = DelayCategory::firstOrCreate(['delay_category' => 'Meal/Rest'], ['description' => 'Lunch or rest break', 'is_active' => true]);
            $refuelDelayCat = DelayCategory::firstOrCreate(['delay_category' => 'Refueling'], ['description' => 'Refueling delay', 'is_active' => true]);
            $unplannedDelayCat = DelayCategory::firstOrCreate(['delay_category' => 'Unplanned'], ['description' => 'Unplanned downtime/stoppage', 'is_active' => true]);

            // Breakdown Types
            $mechBreakdownType = BreakdownType::firstOrCreate(['breakdown_type' => 'Mechanical Breakdown'], ['description' => 'Mechanical issues', 'is_active' => true]);

            // 8. Loop through last 7 days and insert dummy data
            $today = Carbon::today();
            for ($dayOffset = 6; $dayOffset >= 0; $dayOffset--) {
                $planningDate = $today->copy()->subDays($dayOffset);
                $dateStr = $planningDate->toDateString();

                // Create Shift Plan
                $shiftPlan = ShiftPlan::firstOrCreate([
                    'planning_date' => $dateStr,
                    'shift_id' => $shift->id,
                    'site_id' => $site->id,
                ], [
                    'target_bcm' => 2000.00,
                    'supervisor_id' => $supervisorUser->id,
                    'site_incharge_id' => $adminUser->id,
                    'status' => 'completed',
                    'reference_no' => 'PLN-DUMMY-' . $dateStr,
                    'created_by' => $adminUser->id,
                    'published_by' => $adminUser->id,
                    'published_at' => Carbon::now(),
                    'equipment_count' => 7,
                    'actual_bcm' => 0.00,
                ]);

                // Equipment Allocations
                $allocExc1 = ShiftEquipmentAllocation::firstOrCreate([
                    'shift_plan_id' => $shiftPlan->id,
                    'equipment_name_id' => $exc1Name->id,
                ], [
                    'allocated_by' => $adminUser->id,
                    'allocation_time' => Carbon::now(),
                ]);

                $allocExc2 = ShiftEquipmentAllocation::firstOrCreate([
                    'shift_plan_id' => $shiftPlan->id,
                    'equipment_name_id' => $exc2Name->id,
                ], [
                    'allocated_by' => $adminUser->id,
                    'allocation_time' => Carbon::now(),
                ]);

                $allocDmps = [];
                for ($i = 1; $i <= 5; $i++) {
                    $parentExc = ($i <= 3) ? $exc1Name->id : $exc2Name->id;
                    $allocDmps[$i] = ShiftEquipmentAllocation::firstOrCreate([
                        'shift_plan_id' => $shiftPlan->id,
                        'equipment_name_id' => $dmpNames[$i]->id,
                    ], [
                        'parent_equipment_id' => $parentExc,
                        'allocated_by' => $adminUser->id,
                        'allocation_time' => Carbon::now(),
                    ]);
                }

                // Dispatch Trips
                // Seed a random number of trips for each dumper
                $dayTotalBcm = 0;
                $tripCounter = 1;
                for ($i = 1; $i <= 5; $i++) {
                    $numTrips = rand(3, 8);
                    $parentExcId = ($i <= 3) ? $exc1Name->id : $exc2Name->id;

                    for ($t = 1; $t <= $numTrips; $t++) {
                        $quantityBcm = rand(80, 130);
                        $dayTotalBcm += $quantityBcm;

                        $tripTime = $planningDate->copy()->setTime(8, 0)->addMinutes(($i * 40) + ($t * 20));

                        DispatchTrip::create([
                            'trip_reference_no' => 'TRP-DUMMY-' . $dateStr . '-' . $tripCounter++,
                            'shift_plan_id' => $shiftPlan->id,
                            'shift_id' => $shift->id,
                            'site_id' => $site->id,
                            'dumper_equipment_id' => $dmpNames[$i]->id,
                            'driver_id' => $drivers[$i]->id,
                            'excavator_equipment_id' => $parentExcId,
                            'loading_point_id' => $loadingPoint->id,
                            'dumping_point_id' => $dumpingPoint->id,
                            'trip_date_time' => $tripTime,
                            'start_time' => $tripTime->copy()->subMinutes(15),
                            'end_time' => $tripTime,
                            'cycle_time_minutes' => 15.00,
                            'quantity_bcm' => $quantityBcm,
                            'distance_meters' => 1000.00,
                            'total_cycles' => 1,
                            'status' => 'logged',
                            'created_by' => $adminUser->id,
                        ]);
                    }
                }

                // Update actual bcm in shift plan
                $shiftPlan->update(['actual_bcm' => $dayTotalBcm]);

                // Fuel Entries
                for ($i = 1; $i <= 5; $i++) {
                    FuelEntry::create([
                        'fuel_ref_no' => 'FUEL-DUMMY-' . $dateStr . '-' . $i,
                        'shift_plan_id' => $shiftPlan->id,
                        'fuel_log_date' => $planningDate->copy()->setTime(9, 0),
                        'shift_id' => $shift->id,
                        'equipment_allocation_id' => $allocDmps[$i]->id,
                        'equipment_id' => $dumperCat->id,
                        'equipment_name_id' => $dmpNames[$i]->id,
                        'opening_fuel' => rand(150, 200),
                        'fuel_issued' => rand(50, 100),
                        'closing_fuel' => rand(80, 120),
                        'fuel_consumption' => rand(80, 150),
                        'status' => 'active',
                        'created_by' => $adminUser->id,
                    ]);
                }

                // Delays (Meal/Rest, Refueling, Unplanned)
                // Seed some delays to make up shift_delay_analysis
                $mealMins = rand(45, 60);
                $refuelMins = rand(20, 40);
                $unplannedMins = rand(15, 30);

                // Meal/Rest Delay
                Delay::create([
                    'delay_ref_no' => 'DEL-DUMMY-MEAL-' . $dateStr,
                    'shift_plan_id' => $shiftPlan->id,
                    'shift_id' => $shift->id,
                    'shift_date' => $dateStr,
                    'shift_name' => 'Day Shift',
                    'delay_log_date' => $planningDate->copy()->setTime(12, 0),
                    'delay_category_id' => $mealDelayCat->id,
                    'delay_subcategory' => 'Lunch Break',
                    'start_time' => '12:00:00',
                    'end_time' => Carbon::parse('12:00:00')->addMinutes($mealMins)->toTimeString(),
                    'duration_minutes' => $mealMins,
                    'severity' => 'LOW',
                    'description' => 'Scheduled lunch break',
                    'created_by' => $adminUser->id,
                ]);

                // Refueling Delay
                Delay::create([
                    'delay_ref_no' => 'DEL-DUMMY-REFUEL-' . $dateStr,
                    'shift_plan_id' => $shiftPlan->id,
                    'shift_id' => $shift->id,
                    'shift_date' => $dateStr,
                    'shift_name' => 'Day Shift',
                    'delay_log_date' => $planningDate->copy()->setTime(10, 0),
                    'delay_category_id' => $refuelDelayCat->id,
                    'delay_subcategory' => 'Diesel Refueling',
                    'start_time' => '10:00:00',
                    'end_time' => Carbon::parse('10:00:00')->addMinutes($refuelMins)->toTimeString(),
                    'duration_minutes' => $refuelMins,
                    'severity' => 'LOW',
                    'description' => 'Dumper fleet diesel top-up',
                    'created_by' => $adminUser->id,
                ]);

                // Unplanned Delay
                Delay::create([
                    'delay_ref_no' => 'DEL-DUMMY-UNPLANNED-' . $dateStr,
                    'shift_plan_id' => $shiftPlan->id,
                    'shift_id' => $shift->id,
                    'shift_date' => $dateStr,
                    'shift_name' => 'Day Shift',
                    'delay_log_date' => $planningDate->copy()->setTime(14, 0),
                    'delay_category_id' => $unplannedDelayCat->id,
                    'delay_subcategory' => 'Traffic Congestion',
                    'start_time' => '14:00:00',
                    'end_time' => Carbon::parse('14:00:00')->addMinutes($unplannedMins)->toTimeString(),
                    'duration_minutes' => $unplannedMins,
                    'severity' => 'MEDIUM',
                    'description' => 'Congestion at dumping point',
                    'created_by' => $adminUser->id,
                ]);

                // Breakdown Tickets (seeds total downtime)
                if (rand(0, 1) === 1) {
                    $downtimeMins = rand(30, 90);
                    BreakdownTicket::create([
                        'ticket_number' => 'BRK-DUMMY-' . $dateStr,
                        'shift_id' => $shift->id,
                        'equipment_id' => $dumperCat->id,
                        'equipment_name_id' => $dmpNames[rand(1, 5)]->id,
                        'equipment_allocation_id' => $allocDmps[rand(1, 5)]->id,
                        'breakdown_date_time' => $planningDate->copy()->setTime(11, 0),
                        'reported_by' => $drivers[rand(1, 5)]->id,
                        'breakdown_type_id' => $mechBreakdownType->id,
                        'severity' => 'MEDIUM',
                        'description' => 'Air conditioner not working / minor engine check',
                        'status' => 'closed',
                        'downtime_start' => $planningDate->copy()->setTime(11, 0),
                        'downtime_end' => $planningDate->copy()->setTime(11, 0)->addMinutes($downtimeMins),
                        'downtime_minutes' => $downtimeMins,
                        'resolved_by' => $adminUser->id,
                        'resolved_at' => $planningDate->copy()->setTime(11, 0)->addMinutes($downtimeMins),
                    ]);
                }
            }
        });
    }
}
