<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use App\Models\User;
use App\Models\RoleUser;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Site;
use App\Models\Shift;
use App\Models\SitePoint;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\ShiftPlan;
use App\Models\ShiftEquipmentAllocation;
use App\Models\ShiftWorkforceDeployment;
use App\Models\FuelEntry;
use App\Models\DispatchTrip;
use App\Models\BreakdownTicket;
use App\Models\Delay;
use App\Models\DelayCategory;
use Carbon\Carbon;

class ShiftFlowTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Wrap everything in a single transaction
        DB::transaction(function () {
            // ── Prerequisites: Master Data ────────────────────────────

            // 1. Roles
            $roles = [
                ['name' => 'System-Administrator', 'slug' => 'super-admin'],
                ['name' => 'Worker', 'slug' => 'worker'],
                ['name' => 'Supervisor', 'slug' => 'supervisor'],
                ['name' => 'Project Manager', 'slug' => 'project-manager'],
                ['name' => 'Finance Admin', 'slug' => 'finance-admin'],
                ['name' => 'Site Incharge', 'slug' => 'site-incharge'],
                ['name' => 'Driver', 'slug' => 'driver'],
            ];
            foreach ($roles as $roleData) {
                Role::updateOrCreate(['slug' => $roleData['slug']], $roleData);
            }

            $superAdminRole = Role::where('slug', 'super-admin')->first();
            $supervisorRole = Role::where('slug', 'supervisor')->first();
            $inchargeRole = Role::where('slug', 'site-incharge')->first();
            $driverRole = Role::where('slug', 'driver')->first();
            $workerRole = Role::where('slug', 'worker')->first();

            // 2. Users
            $adminUser = User::updateOrCreate(['email' => 'admin@test.com'], [
                'password' => bcrypt('password'),
                'is_active' => true,
            ]);
            RoleUser::updateOrCreate([
                'user_id' => $adminUser->id,
                'role_id' => $superAdminRole->id,
            ]);

            $supervisorUser = User::updateOrCreate(['email' => 'supervisor@test.com'], [
                'password' => bcrypt('password'),
                'is_active' => true,
            ]);
            RoleUser::updateOrCreate([
                'user_id' => $supervisorUser->id,
                'role_id' => $supervisorRole->id,
            ]);

            $inchargeUser = User::updateOrCreate(['email' => 'incharge@test.com'], [
                'password' => bcrypt('password'),
                'is_active' => true,
            ]);
            RoleUser::updateOrCreate([
                'user_id' => $inchargeUser->id,
                'role_id' => $inchargeRole->id,
            ]);

            // 3. Employees (with designation_id pointing to roles)
            $supervisorEmp = Employee::updateOrCreate(['employee_code' => 'EMP-SUP'], [
                'name' => 'Shift Supervisor',
                'joining_date' => '2026-01-01',
                'is_active' => true,
                'designation_id' => $supervisorRole->id,
                'role_user_id' => RoleUser::where('user_id', $supervisorUser->id)->first()->id,
            ]);

            $inchargeEmp = Employee::updateOrCreate(['employee_code' => 'EMP-INC'], [
                'name' => 'Site Incharge',
                'joining_date' => '2026-01-01',
                'is_active' => true,
                'designation_id' => $inchargeRole->id,
                'role_user_id' => RoleUser::where('user_id', $inchargeUser->id)->first()->id,
            ]);

            // 3 Drivers
            $drivers = [];
            $driverUsers = [];
            for ($i = 1; $i <= 3; $i++) {
                $driverUser = User::updateOrCreate(['email' => "driver{$i}@test.com"], [
                    'password' => bcrypt('password'),
                    'is_active' => true,
                ]);
                $driverUsers[$i] = $driverUser;
                $ru = RoleUser::updateOrCreate([
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

            // 2 Operators
            $operators = [];
            $operatorUsers = [];
            for ($i = 1; $i <= 2; $i++) {
                $opUser = User::updateOrCreate(['email' => "operator{$i}@test.com"], [
                    'password' => bcrypt('password'),
                    'is_active' => true,
                ]);
                $operatorUsers[$i] = $opUser;
                $ru = RoleUser::updateOrCreate([
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
            $site = Site::updateOrCreate(['site_name' => 'Site Alpha'], [
                'is_active' => true,
            ]);

            // 5. Shifts
            $shift = Shift::updateOrCreate(['shift_name' => 'Day Shift'], [
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
                'is_night_shift' => 0,
                'minimum_working_hours' => 8,
            ]);

            // 6. Site Points
            $loadingPoint = SitePoint::updateOrCreate([
                'site_id' => $site->id,
                'name' => 'LP-1',
                'type' => 'loading',
            ], [
                'is_active' => true,
                'created_by' => $adminUser->id,
            ]);

            $dumpingPoint = SitePoint::updateOrCreate([
                'site_id' => $site->id,
                'name' => 'DP-1',
                'type' => 'dumping',
            ], [
                'is_active' => true,
                'created_by' => $adminUser->id,
            ]);

            // 7. Equipment Categories & Instances
            $excavatorCat = Equipment::updateOrCreate(['name' => 'Excavator'], ['is_active' => true]);
            $dumperCat = Equipment::updateOrCreate(['name' => 'Dumper'], ['is_active' => true]);

            $exc1Name = EquipmentName::updateOrCreate(['equipment_name' => 'EXC-01'], [
                'equipment_id' => $excavatorCat->id,
                'is_active' => true,
            ]);
            $exc2Name = EquipmentName::updateOrCreate(['equipment_name' => 'EXC-02'], [
                'equipment_id' => $excavatorCat->id,
                'is_active' => true,
            ]);

            $dmp1Name = EquipmentName::updateOrCreate(['equipment_name' => 'DMP-01'], [
                'equipment_id' => $dumperCat->id,
                'is_active' => true,
            ]);
            $dmp2Name = EquipmentName::updateOrCreate(['equipment_name' => 'DMP-02'], [
                'equipment_id' => $dumperCat->id,
                'is_active' => true,
            ]);
            $dmp3Name = EquipmentName::updateOrCreate(['equipment_name' => 'DMP-03'], [
                'equipment_id' => $dumperCat->id,
                'is_active' => true,
            ]);

            // 8. Employee Shift Assignments (needed so they qualify for auto-load or borrowing)
            foreach (array_merge([$supervisorEmp, $inchargeEmp], $drivers, $operators) as $emp) {
                EmployeeShiftAssignment::updateOrCreate([
                    'employee_id' => $emp->id,
                    'shift_id' => $shift->id,
                ], [
                    'from_date' => '2026-06-01',
                    'to_date' => null,
                ]);
            }

            // 9. Delay Category
            $delayCategory = DelayCategory::updateOrCreate(['delay_category' => 'Operational Delay'], [
                'description' => 'Delays during operational shift',
                'is_active' => true,
            ]);

            // 10. Breakdown Types
            $mechBreakdownType = \App\Models\BreakdownType::updateOrCreate(['breakdown_type' => 'Mechanical Breakdown'], [
                'description' => 'Mechanical issues',
                'is_active' => true,
            ]);

            $elecBreakdownType = \App\Models\BreakdownType::updateOrCreate(['breakdown_type' => 'Electrical Breakdown'], [
                'description' => 'Electrical issues',
                'is_active' => true,
            ]);

            // ── Operational Scenario ──────────────────────────────────
            $planningDate = Carbon::createFromFormat('Y-m-d', '2026-07-02');

            // 1. Shift Plan (status: in_progress so we can log transactions)
            $shiftPlan = ShiftPlan::create([
                'planning_date' => $planningDate->toDateString(),
                'shift_id' => $shift->id,
                'site_id' => $site->id,
                'target_bcm' => 1500.00,
                'supervisor_id' => $supervisorUser->id,
                'site_incharge_id' => $inchargeUser->id,
                'status' => 'in_progress',
                'reference_no' => 'PLN-2026-000001',
                'created_by' => $adminUser->id,
                'published_by' => $adminUser->id,
                'published_at' => Carbon::now(),
                'equipment_count' => 5,
                'actual_bcm' => 0.00,
            ]);

            // 2. Equipment Allocations
            $allocExc1 = ShiftEquipmentAllocation::create([
                'shift_plan_id' => $shiftPlan->id,
                'equipment_name_id' => $exc1Name->id,
                'parent_equipment_id' => null,
                'allocated_by' => $adminUser->id,
                'allocation_time' => Carbon::now(),
            ]);

            $allocExc2 = ShiftEquipmentAllocation::create([
                'shift_plan_id' => $shiftPlan->id,
                'equipment_name_id' => $exc2Name->id,
                'parent_equipment_id' => null,
                'allocated_by' => $adminUser->id,
                'allocation_time' => Carbon::now(),
            ]);

            $allocDmp1 = ShiftEquipmentAllocation::create([
                'shift_plan_id' => $shiftPlan->id,
                'equipment_name_id' => $dmp1Name->id,
                'parent_equipment_id' => $exc1Name->id, // Nested under EXC-01
                'allocated_by' => $adminUser->id,
                'allocation_time' => Carbon::now(),
            ]);

            $allocDmp2 = ShiftEquipmentAllocation::create([
                'shift_plan_id' => $shiftPlan->id,
                'equipment_name_id' => $dmp2Name->id,
                'parent_equipment_id' => $exc1Name->id, // Nested under EXC-01
                'allocated_by' => $adminUser->id,
                'allocation_time' => Carbon::now(),
            ]);

            $allocDmp3 = ShiftEquipmentAllocation::create([
                'shift_plan_id' => $shiftPlan->id,
                'equipment_name_id' => $dmp3Name->id,
                'parent_equipment_id' => $exc2Name->id, // Nested under EXC-02
                'allocated_by' => $adminUser->id,
                'allocation_time' => Carbon::now(),
            ]);

            // 3. Workforce Deployments
            ShiftWorkforceDeployment::create([
                'shift_plan_id' => $shiftPlan->id,
                'employee_id' => $operators[1]->id,
                'relay_shift' => 'general',
                'assigned_machine_id' => $allocExc1->id,
                'designation' => 'Worker',
                'is_borrowed' => false,
                'deployed_by' => $adminUser->id,
                'status' => 'active',
            ]);

            ShiftWorkforceDeployment::create([
                'shift_plan_id' => $shiftPlan->id,
                'employee_id' => $operators[2]->id,
                'relay_shift' => 'general',
                'assigned_machine_id' => $allocExc2->id,
                'designation' => 'Worker',
                'is_borrowed' => false,
                'deployed_by' => $adminUser->id,
                'status' => 'active',
            ]);

            ShiftWorkforceDeployment::create([
                'shift_plan_id' => $shiftPlan->id,
                'employee_id' => $drivers[1]->id,
                'relay_shift' => 'general',
                'assigned_machine_id' => $allocDmp1->id,
                'designation' => 'Driver',
                'is_borrowed' => false,
                'deployed_by' => $adminUser->id,
                'status' => 'active',
            ]);

            ShiftWorkforceDeployment::create([
                'shift_plan_id' => $shiftPlan->id,
                'employee_id' => $drivers[2]->id,
                'relay_shift' => 'general',
                'assigned_machine_id' => $allocDmp2->id,
                'designation' => 'Driver',
                'is_borrowed' => false,
                'deployed_by' => $adminUser->id,
                'status' => 'active',
            ]);

            ShiftWorkforceDeployment::create([
                'shift_plan_id' => $shiftPlan->id,
                'employee_id' => $drivers[3]->id,
                'relay_shift' => 'general',
                'assigned_machine_id' => $allocDmp3->id,
                'designation' => 'Driver',
                'is_borrowed' => false,
                'deployed_by' => $adminUser->id,
                'status' => 'active',
            ]);

            // 4. Fuel Entries
            // EXC-01
            FuelEntry::create([
                'fuel_ref_no' => 'FUEL-2026-000001',
                'shift_plan_id' => $shiftPlan->id,
                'fuel_log_date' => $planningDate->toDateString() . ' 08:30:00',
                'shift_id' => $shift->id,
                'equipment_allocation_id' => $allocExc1->id,
                'equipment_id' => $excavatorCat->id,
                'equipment_name_id' => $exc1Name->id,
                'operator_id' => $operatorUsers[1]->id,
                'fuel_source' => 'fuel_station',
                'opening_fuel' => 200.00,
                'fuel_issued' => 150.00,
                'closing_fuel' => 50.00,
                'fuel_consumption' => 300.00,
                'hours_meter_reading' => 100.00,
                'status' => 'active',
                'created_by' => $adminUser->id,
            ]);

            // EXC-02
            FuelEntry::create([
                'fuel_ref_no' => 'FUEL-2026-000002',
                'shift_plan_id' => $shiftPlan->id,
                'fuel_log_date' => $planningDate->toDateString() . ' 08:45:00',
                'shift_id' => $shift->id,
                'equipment_allocation_id' => $allocExc2->id,
                'equipment_id' => $excavatorCat->id,
                'equipment_name_id' => $exc2Name->id,
                'operator_id' => $operatorUsers[2]->id,
                'fuel_source' => 'fuel_station',
                'opening_fuel' => 250.00,
                'fuel_issued' => 100.00,
                'closing_fuel' => 80.00,
                'fuel_consumption' => 270.00,
                'hours_meter_reading' => 120.00,
                'status' => 'active',
                'created_by' => $adminUser->id,
            ]);

            // DMP-01
            FuelEntry::create([
                'fuel_ref_no' => 'FUEL-2026-000003',
                'shift_plan_id' => $shiftPlan->id,
                'fuel_log_date' => $planningDate->toDateString() . ' 09:00:00',
                'shift_id' => $shift->id,
                'equipment_allocation_id' => $allocDmp1->id,
                'equipment_id' => $dumperCat->id,
                'equipment_name_id' => $dmp1Name->id,
                'operator_id' => $driverUsers[1]->id,
                'fuel_source' => 'fuel_station',
                'opening_fuel' => 100.00,
                'fuel_issued' => 80.00,
                'closing_fuel' => 20.00,
                'fuel_consumption' => 160.00,
                'kilometer_reading' => 500.00,
                'status' => 'active',
                'created_by' => $adminUser->id,
            ]);

            // DMP-02
            FuelEntry::create([
                'fuel_ref_no' => 'FUEL-2026-000004',
                'shift_plan_id' => $shiftPlan->id,
                'fuel_log_date' => $planningDate->toDateString() . ' 09:15:00',
                'shift_id' => $shift->id,
                'equipment_allocation_id' => $allocDmp2->id,
                'equipment_id' => $dumperCat->id,
                'equipment_name_id' => $dmp2Name->id,
                'operator_id' => $driverUsers[2]->id,
                'fuel_source' => 'fuel_station',
                'opening_fuel' => 120.00,
                'fuel_issued' => 60.00,
                'closing_fuel' => 30.00,
                'fuel_consumption' => 150.00,
                'kilometer_reading' => 600.00,
                'status' => 'active',
                'created_by' => $adminUser->id,
            ]);

            // DMP-03
            FuelEntry::create([
                'fuel_ref_no' => 'FUEL-2026-000005',
                'shift_plan_id' => $shiftPlan->id,
                'fuel_log_date' => $planningDate->toDateString() . ' 09:30:00',
                'shift_id' => $shift->id,
                'equipment_allocation_id' => $allocDmp3->id,
                'equipment_id' => $dumperCat->id,
                'equipment_name_id' => $dmp3Name->id,
                'operator_id' => $driverUsers[3]->id,
                'fuel_source' => 'fuel_station',
                'opening_fuel' => 110.00,
                'fuel_issued' => 70.00,
                'closing_fuel' => 40.00,
                'fuel_consumption' => 140.00,
                'kilometer_reading' => 550.00,
                'status' => 'active',
                'created_by' => $adminUser->id,
            ]);

            // 5. Dispatch Trips
            // Dumper 1 (High Quantity, Poor Performance)
            // Trip 1
            \App\Models\DispatchTrip::create([
                'trip_reference_no' => 'TRP-2026-000001',
                'shift_plan_id' => $shiftPlan->id,
                'shift_id' => $shift->id,
                'site_id' => $site->id,
                'dumper_equipment_id' => $dmp1Name->id,
                'driver_id' => $drivers[1]->id,
                'excavator_equipment_id' => $exc1Name->id,
                'loading_point_id' => $loadingPoint->id,
                'dumping_point_id' => $dumpingPoint->id,
                'trip_date_time' => $planningDate->toDateString() . ' 08:30:00',
                'start_time' => $planningDate->toDateString() . ' 08:10:00',
                'end_time' => $planningDate->toDateString() . ' 08:30:00',
                'cycle_time_minutes' => 20.00,
                'quantity_bcm' => 100.00,
                'distance_meters' => 1000.00,
                'status' => 'logged',
                'created_by' => $adminUser->id,
            ]);
            // Trip 2
            \App\Models\DispatchTrip::create([
                'trip_reference_no' => 'TRP-2026-000002',
                'shift_plan_id' => $shiftPlan->id,
                'shift_id' => $shift->id,
                'site_id' => $site->id,
                'dumper_equipment_id' => $dmp1Name->id,
                'driver_id' => $drivers[1]->id,
                'excavator_equipment_id' => $exc1Name->id,
                'loading_point_id' => $loadingPoint->id,
                'dumping_point_id' => $dumpingPoint->id,
                'trip_date_time' => $planningDate->toDateString() . ' 09:10:00',
                'start_time' => $planningDate->toDateString() . ' 08:45:00',
                'end_time' => $planningDate->toDateString() . ' 09:10:00',
                'cycle_time_minutes' => 25.00,
                'quantity_bcm' => 120.00,
                'distance_meters' => 1000.00,
                'status' => 'logged',
                'created_by' => $adminUser->id,
            ]);

            // Dumper 2 (Lower Quantity, Faster Cycle Time)
            // Trip 1
            \App\Models\DispatchTrip::create([
                'trip_reference_no' => 'TRP-2026-000003',
                'shift_plan_id' => $shiftPlan->id,
                'shift_id' => $shift->id,
                'site_id' => $site->id,
                'dumper_equipment_id' => $dmp2Name->id,
                'driver_id' => $drivers[2]->id,
                'excavator_equipment_id' => $exc1Name->id,
                'loading_point_id' => $loadingPoint->id,
                'dumping_point_id' => $dumpingPoint->id,
                'trip_date_time' => $planningDate->toDateString() . ' 08:25:00',
                'start_time' => $planningDate->toDateString() . ' 08:10:00',
                'end_time' => $planningDate->toDateString() . ' 08:25:00',
                'cycle_time_minutes' => 15.00,
                'quantity_bcm' => 80.00,
                'distance_meters' => 1000.00,
                'status' => 'logged',
                'created_by' => $adminUser->id,
            ]);
            // Trip 2
            \App\Models\DispatchTrip::create([
                'trip_reference_no' => 'TRP-2026-000004',
                'shift_plan_id' => $shiftPlan->id,
                'shift_id' => $shift->id,
                'site_id' => $site->id,
                'dumper_equipment_id' => $dmp2Name->id,
                'driver_id' => $drivers[2]->id,
                'excavator_equipment_id' => $exc1Name->id,
                'loading_point_id' => $loadingPoint->id,
                'dumping_point_id' => $dumpingPoint->id,
                'trip_date_time' => $planningDate->toDateString() . ' 08:50:00',
                'start_time' => $planningDate->toDateString() . ' 08:35:00',
                'end_time' => $planningDate->toDateString() . ' 08:50:00',
                'cycle_time_minutes' => 15.00,
                'quantity_bcm' => 90.00,
                'distance_meters' => 1000.00,
                'status' => 'logged',
                'created_by' => $adminUser->id,
            ]);

            // Dumper 3 (Moderate Quantity)
            // Trip 1
            \App\Models\DispatchTrip::create([
                'trip_reference_no' => 'TRP-2026-000005',
                'shift_plan_id' => $shiftPlan->id,
                'shift_id' => $shift->id,
                'site_id' => $site->id,
                'dumper_equipment_id' => $dmp3Name->id,
                'driver_id' => $drivers[3]->id,
                'excavator_equipment_id' => $exc2Name->id,
                'loading_point_id' => $loadingPoint->id,
                'dumping_point_id' => $dumpingPoint->id,
                'trip_date_time' => $planningDate->toDateString() . ' 08:32:00',
                'start_time' => $planningDate->toDateString() . ' 08:15:00',
                'end_time' => $planningDate->toDateString() . ' 08:32:00',
                'cycle_time_minutes' => 17.00,
                'quantity_bcm' => 95.00,
                'distance_meters' => 1000.00,
                'status' => 'logged',
                'created_by' => $adminUser->id,
            ]);

            // Update shift plan actual bcm to reflect seeded trips
            $totalBcm = DispatchTrip::where('shift_plan_id', $shiftPlan->id)->sum('quantity_bcm');
            $shiftPlan->update(['actual_bcm' => $totalBcm]);

            // Recalculate fuel entry bcm
            $fuelService = resolve(\App\Services\FuelService::class);
            $fuelService->recalculateFuelEntryBcm($shiftPlan->id, $exc1Name->id);
            $fuelService->recalculateFuelEntryBcm($shiftPlan->id, $exc2Name->id);
            $fuelService->recalculateFuelEntryBcm($shiftPlan->id, $dmp1Name->id);
            $fuelService->recalculateFuelEntryBcm($shiftPlan->id, $dmp2Name->id);
            $fuelService->recalculateFuelEntryBcm($shiftPlan->id, $dmp3Name->id);

            // 6. Breakdown Tickets (downtime start and end)
            // Ticket 1: 120 minutes downtime (Closed)
            $ticket1 = BreakdownTicket::create([
                'ticket_number' => 'BRK-2026-000001',
                'shift_id' => $shift->id,
                'equipment_id' => $dumperCat->id,
                'equipment_name_id' => $dmp1Name->id,
                'equipment_allocation_id' => $allocDmp1->id,
                'breakdown_date_time' => $planningDate->toDateString() . ' 09:30:00',
                'reported_by' => $supervisorEmp->id,
                'breakdown_type_id' => $mechBreakdownType->id,
                'severity' => 'MEDIUM',
                'description' => 'Engine heating issue',
                'status' => 'closed',
                'downtime_start' => $planningDate->toDateString() . ' 09:30:00',
                'downtime_end' => $planningDate->toDateString() . ' 11:30:00',
                'downtime_minutes' => 120,
                'resolution_notes' => 'Coolant level refilled',
                'resolved_by' => $adminUser->id,
                'resolved_at' => $planningDate->toDateString() . ' 11:30:00',
            ]);

            // Ticket 2: 180 minutes downtime (Closed)
            BreakdownTicket::create([
                'ticket_number' => 'BRK-2026-000002',
                'shift_id' => $shift->id,
                'equipment_id' => $excavatorCat->id,
                'equipment_name_id' => $exc1Name->id,
                'equipment_allocation_id' => $allocExc1->id,
                'breakdown_date_time' => $planningDate->toDateString() . ' 12:00:00',
                'reported_by' => $supervisorEmp->id,
                'breakdown_type_id' => $mechBreakdownType->id,
                'severity' => 'HIGH',
                'description' => 'Hydraulic pressure drop',
                'status' => 'closed',
                'downtime_start' => $planningDate->toDateString() . ' 12:00:00',
                'downtime_end' => $planningDate->toDateString() . ' 15:00:00',
                'downtime_minutes' => 180,
                'resolution_notes' => 'Hydraulic seal replaced',
                'resolved_by' => $adminUser->id,
                'resolved_at' => $planningDate->toDateString() . ' 15:00:00',
            ]);

            // Ticket 3: Open Ticket (still down)
            BreakdownTicket::create([
                'ticket_number' => 'BRK-2026-000003',
                'shift_id' => $shift->id,
                'equipment_id' => $dumperCat->id,
                'equipment_name_id' => $dmp3Name->id,
                'equipment_allocation_id' => $allocDmp3->id,
                'breakdown_date_time' => $planningDate->toDateString() . ' 14:30:00',
                'reported_by' => $supervisorEmp->id,
                'breakdown_type_id' => $elecBreakdownType->id,
                'severity' => 'MEDIUM',
                'description' => 'Alternator failure',
                'status' => 'open',
                'downtime_start' => $planningDate->toDateString() . ' 14:30:00',
                'downtime_end' => null,
                'downtime_minutes' => 0,
            ]);

            // 7. Delay Analysis logs
            // Delay 1: Linked to Breakdown Ticket 1
            Delay::create([
                'delay_ref_no' => 'DEL-2026-000001',
                'shift_plan_id' => $shiftPlan->id,
                'shift_id' => $shift->id,
                'shift_date' => $planningDate->toDateString(),
                'shift_name' => 'Day Shift',
                'delay_log_date' => $planningDate->toDateString() . ' 09:30:00',
                'delay_category_id' => $delayCategory->id,
                'delay_subcategory' => 'Mechanical Repair',
                'start_time' => $planningDate->toDateString() . ' 09:30:00',
                'end_time' => $planningDate->toDateString() . ' 11:30:00',
                'duration_minutes' => 120,
                'severity' => 'MEDIUM',
                'linked_breakdown_id' => $ticket1->id,
                'equipment_id' => $dumperCat->id,
                'equipment_name_id' => $dmp1Name->id,
                'average_production_rate_per_hour' => 50.00,
                'estimated_production_loss_bcm' => 100.00,
                'description' => 'DMP-01 Engine Heating Delay',
                'created_by' => $adminUser->id,
            ]);
        });
    }
}
