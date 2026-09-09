<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
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
use App\Models\AttendanceProcessed;
use Carbon\Carbon;

class ShiftClosureFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $supervisorUser;
    protected $supervisorEmp;
    protected $inchargeUser;
    protected $inchargeEmp;
    protected $site;
    protected $shift;
    protected $loadingPoint;
    protected $dumpingPoint;
    protected $excavatorCat;
    protected $dumperCat;
    protected $exc1Name;
    protected $dmp1Name;
    protected $driverEmp;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles
        $superAdminRole = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);
        $supervisorRole = Role::create(['name' => 'Supervisor', 'slug' => 'supervisor', 'is_active' => 1]);
        $inchargeRole = Role::create(['name' => 'Site Incharge', 'slug' => 'site-incharge', 'is_active' => 1]);
        $driverRole = Role::create(['name' => 'Driver', 'slug' => 'driver', 'is_active' => 1]);

        // 2. Users
        $this->adminUser = User::create(['email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->adminUser->roles()->attach($superAdminRole);

        $this->supervisorUser = User::create(['email' => 'supervisor@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->supervisorUser->roles()->attach($supervisorRole);

        $this->inchargeUser = User::create(['email' => 'incharge@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->inchargeUser->roles()->attach($inchargeRole);

        // 3. Employees
        $this->supervisorEmp = Employee::create([
            'employee_code' => 'EMP-SUP',
            'name' => 'Shift Supervisor',
            'joining_date' => '2026-01-01',
            'is_active' => true,
            'designation_id' => $supervisorRole->id,
            'role_user_id' => RoleUser::create(['user_id' => $this->supervisorUser->id, 'role_id' => $supervisorRole->id])->id,
        ]);

        $this->inchargeEmp = Employee::create([
            'employee_code' => 'EMP-INC',
            'name' => 'Site Incharge',
            'joining_date' => '2026-01-01',
            'is_active' => true,
            'designation_id' => $inchargeRole->id,
            'role_user_id' => RoleUser::create(['user_id' => $this->inchargeUser->id, 'role_id' => $inchargeRole->id])->id,
        ]);

        $driverUser = User::create(['email' => "driver1@test.com", 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->driverEmp = Employee::create([
            'employee_code' => "EMP-D1",
            'name' => "Driver 1",
            'joining_date' => '2026-01-01',
            'is_active' => true,
            'designation_id' => $driverRole->id,
            'role_user_id' => RoleUser::create(['user_id' => $driverUser->id, 'role_id' => $driverRole->id])->id,
        ]);

        // 4. Sites
        $this->site = Site::create(['site_name' => 'Site Alpha', 'is_active' => true]);

        // 5. Shifts
        $this->shift = Shift::create([
            'shift_name' => 'Day Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'is_night_shift' => 0,
            'minimum_working_hours' => 8,
        ]);

        // 6. Site Points
        $this->loadingPoint = SitePoint::create([
            'site_id' => $this->site->id,
            'name' => 'LP-1',
            'type' => 'loading',
            'is_active' => true,
            'created_by' => $this->adminUser->id,
        ]);

        $this->dumpingPoint = SitePoint::create([
            'site_id' => $this->site->id,
            'name' => 'DP-1',
            'type' => 'dumping',
            'is_active' => true,
            'created_by' => $this->adminUser->id,
        ]);

        // 7. Equipment Categories & Instances
        $this->excavatorCat = Equipment::create(['name' => 'Excavator', 'is_active' => true]);
        $this->dumperCat = Equipment::create(['name' => 'Dumper', 'is_active' => true]);

        $this->exc1Name = EquipmentName::create(['equipment_name' => 'EXC-01', 'equipment_id' => $this->excavatorCat->id, 'is_active' => true]);
        $this->dmp1Name = EquipmentName::create(['equipment_name' => 'DMP-01', 'equipment_id' => $this->dumperCat->id, 'is_active' => true]);

        // 8. Employee Shift Assignments
        foreach ([$this->supervisorEmp, $this->inchargeEmp, $this->driverEmp] as $emp) {
            EmployeeShiftAssignment::create([
                'employee_id' => $emp->id,
                'shift_id' => $this->shift->id,
                'from_date' => '2026-06-01',
                'to_date' => null,
            ]);
        }

        Sanctum::actingAs($this->adminUser);
    }

    public function test_cannot_close_shift_without_verifying_checklist()
    {
        // 1. Create shift plan
        $response = $this->postJson('/api/v1/admin/shift-plans', [
            'planning_date' => '2026-07-02',
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1500.00,
            'supervisor_id' => $this->supervisorEmp->id,
            'site_incharge_id' => $this->inchargeEmp->id,
        ]);
        $response->assertStatus(201);
        $shiftPlanId = $response->json('data.id');

        // 2. Try closing without checkboxes
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/close", [
            'supervisor_remarks' => 'This is a long supervisor remark that meets the minimum length requirement.',
            'handover_notes' => 'Some handover notes',
            'closure_confirmed' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonStructure(['errors']);

        $errors = $response->json('errors');
        $this->assertArrayHasKey('attendance_submitted', $errors);
        $this->assertArrayHasKey('fuel_logs_available', $errors);
        $this->assertArrayHasKey('delay_logs_updated', $errors);
        $this->assertArrayHasKey('breakdown_logs_updated', $errors);
        $this->assertArrayHasKey('production_data_available', $errors);
        $this->assertArrayHasKey('safety_data_reviewed', $errors);
    }

    public function test_can_close_shift_successfully_when_checklist_verified()
    {
        // 1. Create shift plan
        $response = $this->postJson('/api/v1/admin/shift-plans', [
            'planning_date' => '2026-07-02',
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1500.00,
            'supervisor_id' => $this->supervisorEmp->id,
            'site_incharge_id' => $this->inchargeEmp->id,
        ]);
        $response->assertStatus(201);
        $shiftPlanId = $response->json('data.id');

        // 2. Allocate EXC-01
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/equipment", [
            'machine_id' => $this->exc1Name->id,
            'parent_category_id' => null,
        ]);
        $response->assertStatus(201);
        $allocExc1Id = $response->json('data.allocation_id');

        // 2b. Allocate DMP-01 under EXC-01
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/equipment", [
            'machine_id' => $this->dmp1Name->id,
            'parent_category_id' => $this->exc1Name->id,
        ]);
        $response->assertStatus(201);
        $allocDmp1Id = $response->json('data.allocation_id');

        // 3. Load Workforce Deployment
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/workforce/load-relay");
        $response->assertStatus(200);

        // Assign machines to deployments in database
        $deployments = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)->get();
        foreach ($deployments as $dep) {
            if ($dep->employee_id === $this->driverEmp->id) {
                $dep->update(['assigned_machine_id' => $allocDmp1Id]);
            }
        }

        // 4. Publish shift plan
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/publish");
        $response->assertStatus(200);

        // 5. Fuel Entry for EXC-01
        $response = $this->postJson('/api/v1/admin/fuel-entries', [
            'shift_plan_id' => $shiftPlanId,
            'fuel_log_date' => '2026-07-02 08:30:00',
            'equipment_allocation_id' => $allocExc1Id,
            'operator_id' => $this->adminUser->id,
            'fuel_source' => 'fuel_station',
            'opening_fuel' => 200.00,
            'fuel_issued' => 150.00,
            'closing_fuel' => 50.00,
            'hours_meter_reading' => 100.00,
        ]);
        $response->assertStatus(201);

        // 5b. Fuel Entry for DMP-01
        $response = $this->postJson('/api/v1/admin/fuel-entries', [
            'shift_plan_id' => $shiftPlanId,
            'fuel_log_date' => '2026-07-02 08:30:00',
            'equipment_allocation_id' => $allocDmp1Id,
            'operator_id' => $this->adminUser->id,
            'fuel_source' => 'fuel_station',
            'opening_fuel' => 200.00,
            'fuel_issued' => 150.00,
            'closing_fuel' => 50.00,
            'kilometer_reading' => 100.00,
        ]);
        $response->assertStatus(201);

        // 6. Dispatch Trip (production data)
        $response = $this->postJson('/api/v1/dispatch/trips', [
            'shift_plan_id' => $shiftPlanId,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dmp1Name->id,
            'driver_id' => $this->driverEmp->id,
            'excavator_equipment_id' => $this->exc1Name->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '08:10:00',
            'end_time' => '08:30:00',
            'quantity_bcm' => 100.00,
            'distance_meters' => 1000.00,
            'total_cycles' => 1,
        ]);
        $response->assertStatus(201);

        // 7. Attendance
        AttendanceProcessed::create([
            'shift_id' => $this->shift->id,
            'date' => '2026-07-02',
            'employee_id' => $this->supervisorEmp->id,
            'status' => 'present',
        ]);

        // 8. Close Shift with verified checklist
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/close", [
            'supervisor_remarks' => 'This is a long supervisor remark that meets the minimum length requirement.',
            'handover_notes' => 'Some handover notes',
            'closure_confirmed' => true,
            'attendance_submitted' => true,
            'fuel_logs_available' => true,
            'delay_logs_updated' => true,
            'breakdown_logs_updated' => true,
            'production_data_available' => true,
            'safety_data_reviewed' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('message', 'Shift Closed Successfully.');

        $this->assertDatabaseHas('shift_plans', [
            'id' => $shiftPlanId,
            'status' => 'completed',
        ]);
    }
}
