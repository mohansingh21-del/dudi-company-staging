<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Controllers
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api\AuthController;

use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Http\Controllers\Api\Admin\DepartmentsController;
use App\Http\Controllers\Api\Admin\BranchController;
use App\Http\Controllers\Api\Admin\DesignationController;
use App\Http\Controllers\Api\Admin\SiteController;
use App\Http\Controllers\Api\Admin\ShiftController;
use App\Http\Controllers\Api\Admin\RelayController;
use App\Http\Controllers\Api\Admin\EmployeeShiftAssignmentController;
use App\Http\Controllers\Api\Admin\LeaveTypeController;
use App\Http\Controllers\Api\Admin\HolidayController;

use App\Http\Controllers\Api\Admin\TrainingTypeController;

use App\Http\Controllers\Api\Admin\AttendanceController;
use App\Http\Controllers\Api\Admin\AttendanceController as AdminAttendanceController;

use App\Http\Controllers\Api\StateController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\Admin\ShiftChangeController;
use App\Http\Controllers\Api\Admin\LeaveController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\SubCategoryController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\InventoryController;
use App\Http\Controllers\Api\Admin\PenaltyController;
use App\Http\Controllers\Api\Admin\PayrollController;
use App\Http\Controllers\Api\Admin\EmployeePayrollController;
use App\Http\Controllers\Api\Admin\EquipmentController;
use App\Http\Controllers\Api\Admin\EquipmentNameController;
use App\Http\Controllers\Api\Admin\IncidentTypeController;
use App\Http\Controllers\Api\Admin\IncidentController;
use App\Http\Controllers\Api\Admin\EquipmentAllocationController;
use App\Http\Controllers\Api\Admin\ShiftPlanController;
use App\Http\Controllers\Api\Admin\WorkforceDeploymentController;
use App\Http\Controllers\Api\Admin\BreakdownController;
use App\Http\Controllers\Api\Admin\BreakdownTypeController;
use App\Http\Controllers\Api\Admin\DelayCategoryController;
use App\Http\Controllers\Api\Admin\FuelEntryController;
use App\Http\Controllers\Api\Admin\DelayController;
use App\Http\Controllers\Api\Admin\SitePointController;
use App\Http\Controllers\Api\Admin\DispatchTripController;
use App\Http\Controllers\Api\Admin\ShiftClosureController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\ServiceRecordController;

/*
|--------------------------------------------------------------------------
| NEW Mining Workforce Controllers
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    Route::prefix('auth')->group(function () {

        Route::post('login', [AuthController::class, 'login']);

        Route::post('send-otp', [AuthController::class, 'sendOtp']);

        Route::post('verify-otp', [AuthController::class, 'verifyOtp']);

        Route::post('resend-otp', [AuthController::class, 'resendOtp']);
        Route::post('password/request', [AuthController::class, 'requestPasswordOtp'])->middleware('throttle:5,1'); // 5 requests per minute per IP
        Route::post('password/verify', [AuthController::class, 'verifyPasswordOtp'])->middleware('throttle:5,1');
        Route::post('password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1'); // 5 requests per minute per IP

    });

    /*
    |--------------------------------------------------------------------------
    | Public APIs
    |--------------------------------------------------------------------------
    */

    Route::get('/states', [StateController::class, 'getStates']);

    Route::get('/states/{id}/cities', [CityController::class, 'getCities']);

    Route::get('/shifts/by-datetime', [ShiftController::class, 'findShiftByDateTime']);

    /*
    |--------------------------------------------------------------------------
    | Employee Attendance APIs
    |--------------------------------------------------------------------------
    */

    Route::middleware(['auth:sanctum'])
        ->prefix('attendance')
        ->group(function () {

            Route::post('mark-in', [AttendanceController::class, 'markAttendance']);

            Route::post('mark-out', [AttendanceController::class, 'markOutAttendance']);

            Route::post('history', [AttendanceController::class, 'getAttendance']);

            Route::post('status', [AttendanceController::class, 'checkStatus']);

            Route::post('correction', [AttendanceController::class, 'requestCorrection']);
        });

    /*
    |--------------------------------------------------------------------------
    | ADMIN APIs
    |--------------------------------------------------------------------------
    */
    // Route::middleware('auth:sanctum')->get('/auth-check', function () {
    //     return response()->json([
    //         'message' => 'AUTH WORKING',
    //         'user' => auth()->user()
    //     ]);
    // });

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::prefix('dashboard')->group(function () {
            Route::get('summary', [DashboardController::class, 'summary']);
            Route::get('fuel/top-consumers', [DashboardController::class, 'topConsumers']);
            Route::get('fuel/low-efficiency', [DashboardController::class, 'lowEfficiency']);
            Route::get('fuel/recent-transactions', [DashboardController::class, 'recentTransactions']);
            Route::get('delay/top-categories', [DashboardController::class, 'topCategories']);
            Route::get('delay/critical-delays', [DashboardController::class, 'criticalDelays']);
            Route::get('delay/recent-delays', [DashboardController::class, 'recentDelays']);
            Route::get('dispatch/recent-trips', [DashboardController::class, 'recentTrips']);
        });
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::get('incident-types', [IncidentTypeController::class, 'publicIndex']);
        Route::get('breakdown-types', [BreakdownTypeController::class, 'publicIndex']);
        Route::get('delay-categories', [DelayCategoryController::class, 'publicIndex']);
        Route::get('shifts', [ShiftController::class, 'getPublicShifts']);
        Route::get('relays', [RelayController::class, 'getPublicRelays']);
        Route::get('employees', [EmployeeController::class, 'getPublicEmployees']);
        Route::get('active-employees', [EmployeeController::class, 'getActiveEmployees']);
        Route::get('sites', [SiteController::class, 'getPublicSites']);
        Route::get('site-points', [SitePointController::class, 'index']);
        Route::get('departments', [DepartmentsController::class, 'getPublicDepartments']);
        Route::get('designations', [DesignationController::class, 'getPublicDesignations']);
        Route::get('products', [ProductController::class, 'getPublicProducts']);
        Route::get('categories', [CategoryController::class, 'getPublicCategories']);
        Route::get('subcategories', [SubCategoryController::class, 'getPublicSubCategories']);
        Route::get('machine-categories', [EquipmentController::class, 'listCategories']);
        Route::get('active-machines', [EquipmentNameController::class, 'getActiveMachines']);
        Route::get('available-products', [InventoryController::class, 'getAvailableProducts']);
        Route::get('open-breakdowns', [BreakdownController::class, 'getOpenBreakdowns']);
        Route::get('machine-names/{id}', [EquipmentNameController::class, 'getPublicEquipmentNames']);
        Route::get('shift-plans/{shift_id}/machines', [EquipmentAllocationController::class, 'getPublicMachines']);
        Route::get('search-employee/search={search}', [EmployeeController::class, 'searchEmployeeByName']);

        /*
        |--------------------------------------------------------------------------
        | Module 7: Dispatch & Dumping Operations
        |--------------------------------------------------------------------------
        */
        Route::get('dispatch/dashboard', [DispatchTripController::class, 'dashboard']);
        Route::get('dispatch/trips', [DispatchTripController::class, 'index']);
        Route::get('dispatch/trips/{id}', [DispatchTripController::class, 'show']);
        Route::get('dispatch/dumper-summary', [DispatchTripController::class, 'dumperSummary']);
        Route::get('dispatch/fleet-performance', [DispatchTripController::class, 'fleetPerformance']);

        Route::middleware(['role:super-admin,supervisor,site-incharge'])->group(function () {
            Route::post('dispatch/trips/import', [DispatchTripController::class, 'import']);
            Route::post('dispatch/trips', [DispatchTripController::class, 'store']);
            Route::put('dispatch/trips/{id}', [DispatchTripController::class, 'update']);
        });

        // ADMIN ONLY
        Route::middleware(['role:super-admin'])->prefix('admin')->group(function () {

            Route::apiResource('employees', EmployeeController::class);

            Route::post('employees/bulk-upload', [EmployeeController::class, 'bulkUpload']);

            Route::patch('employees/{id}/status', [EmployeeController::class, 'toggleStatus']);

            Route::delete('{id}', [EmployeeController::class, 'destroy']);

            Route::apiResource('departments', DepartmentsController::class);

            Route::patch('departments/{id}/status', [DepartmentsController::class, 'toggleStatus']);
            Route::apiResource('designation', DesignationController::class);
            Route::patch('designation/{id}/status', [DesignationController::class, 'toggleStatus']);
            Route::apiResource('sites', SiteController::class);

            Route::patch('sites/{id}/status', [SiteController::class, 'toggleStatus']);
            Route::apiResource('shift', ShiftController::class);
            Route::patch('shift/{id}/status', [ShiftController::class, 'toggleStatus']);

            Route::apiResource('relays', RelayController::class);
            Route::patch('relays/{id}/status', [RelayController::class, 'toggleStatus']);
            Route::apiResource('leavetype', LeaveTypeController::class);

            Route::patch('leavetype/{id}/status', [LeaveTypeController::class, 'toggleStatus']);
            Route::apiResource('holiday', HolidayController::class);

            Route::patch('holiday/{id}/status', [HolidayController::class, 'toggleStatus']);
            Route::apiResource('trainingtype', TrainingTypeController::class);

            Route::patch('trainingtype/{id}/status', [TrainingTypeController::class, 'toggleStatus']);
            Route::post('attendance/bulk-upload', [AttendanceController::class, 'bulkUpload']);
            Route::patch('attendance/bulk-status', [AttendanceController::class, 'bulkUpdateStatus']);
            Route::get('attendance/employee/{employee_id}', [AttendanceController::class, 'getEmployeeAttendanceDetails']);
            Route::post('attendance/correction', [AttendanceController::class, 'update']);

            Route::apiResource('attendance', AttendanceController::class);
            Route::patch('attendance/{id}/status', [AttendanceController::class, 'updateStatus']);
            ////php artisan migrate --path=/database/migrations/2026_06_26_115046_create_breakdown_types_table.php

            Route::post('leaves/bulk-upload', [LeaveController::class, 'bulkUpload']);
            Route::post('leaves/{id}/approve-reject', [LeaveController::class, 'approveReject']);
            Route::apiResource('leaves', LeaveController::class);

            Route::apiResource('employee-payrolls', EmployeePayrollController::class);
            Route::apiResource('equipments', EquipmentController::class);

            Route::patch('equipments/{id}/status', [EquipmentController::class, 'toggleStatus']);
            Route::apiResource('equipment-names', EquipmentNameController::class);
            Route::patch('equipment-names/{id}/status', [EquipmentNameController::class, 'toggleStatus']);
            Route::apiResource('breakdown-types', BreakdownTypeController::class);
            Route::patch('breakdown-types/{id}/status', [BreakdownTypeController::class, 'toggleStatus']);
            Route::apiResource('delay-categories', DelayCategoryController::class);
            Route::patch('delay-categories/{id}/status', [DelayCategoryController::class, 'toggleStatus']);

            /*
            |--------------------------------------------------------------------------
            | Loading/Dumping Points (Site Points)
            |--------------------------------------------------------------------------
            */
            Route::get('site-points', [SitePointController::class, 'adminIndex']);
            Route::get('site-points/{id}', [SitePointController::class, 'show']);
            Route::post('site-points', [SitePointController::class, 'store']);
            Route::put('site-points/{id}', [SitePointController::class, 'update']);
            Route::patch('site-points/{id}/status', [SitePointController::class, 'toggleStatus']);

            Route::apiResource('incident-types', IncidentTypeController::class);

            Route::patch('incident-types/{id}/status', [IncidentTypeController::class, 'toggleStatus']);
            Route::post('incidents/import', [IncidentController::class, 'import']);
            Route::apiResource('incidents', IncidentController::class);
            Route::patch(
                'incidents/{incident}',
                [IncidentController::class, 'close']
            );
        });

        // NORMAL USER

    });

    Route::middleware(['auth:sanctum', 'role:super-admin,supervisor'])->prefix('admin')
        ->group(function () {



            /*
            |--------------------------------------------------------------------------
            | Departments
            |--------------------------------------------------------------------------
            */

            Route::prefix('departments')->group(function () {

                Route::get('/', [DepartmentsController::class, 'index']);

                Route::get('{id}', [DepartmentsController::class, 'show']);

                Route::post('/', [DepartmentsController::class, 'store']);

                Route::patch('{id}/status', [DepartmentsController::class, 'toggleStatus']);

                Route::delete('{id}', [DepartmentsController::class, 'destroy']);
            });

            /*
            |--------------------------------------------------------------------------
            | Categories
            |--------------------------------------------------------------------------
            */

            Route::apiResource('categories', CategoryController::class);
            Route::post('categories/{id}', [CategoryController::class, 'update']);
            Route::patch('categories/{id}/status', [CategoryController::class, 'toggleStatus']);


            /*
            |--------------------------------------------------------------------------
            | Sub Categories
            |--------------------------------------------------------------------------
            */

            Route::apiResource('subcategories', SubCategoryController::class);
            Route::post('subcategories/{id}', [SubCategoryController::class, 'update']);
            Route::patch('subcategories/{id}/status', [SubCategoryController::class, 'toggleStatus']);


            /*
            |--------------------------------------------------------------------------
            | Products
            |--------------------------------------------------------------------------
            */

            Route::apiResource('products', ProductController::class);
            Route::post('products/{id}', [ProductController::class, 'update']);
            Route::patch('products/{id}/status', [ProductController::class, 'toggleStatus']);

            /*
            |--------------------------------------------------------------------------
            | Inventory Management
            |--------------------------------------------------------------------------
            */

            Route::prefix('inventories')->group(function () {
                Route::get('/', [InventoryController::class, 'index']);
                Route::post('add', [InventoryController::class, 'store']);
                Route::post('assign', [InventoryController::class, 'assign']);
                Route::post('update-quantity/{id}', [InventoryController::class, 'updateQuantity']);
                Route::post('bulk-upload', [InventoryController::class, 'bulkUpload']);
                Route::get('{productId}/logs', [InventoryController::class, 'logs']);
                Route::get('assignments', [InventoryController::class, 'assignments']);
                Route::get('{id}', [InventoryController::class, 'show']);
            });

            /*
            |--------------------------------------------------------------------------
            | Sites
            |--------------------------------------------------------------------------
            */


            /*
            |--------------------------------------------------------------------------
            | Employees
            |--------------------------------------------------------------------------
            */



            /*
            |--------------------------------------------------------------------------
            | Employee Shift Assignment
            |--------------------------------------------------------------------------
            */

            Route::post('employee-shift-assignments/bulk-upload', [EmployeeShiftAssignmentController::class, 'bulkUpload']);
            Route::apiResource('employee-shift-assignments', EmployeeShiftAssignmentController::class);

            /*
            |--------------------------------------------------------------------------
            | Shift Changes
            |--------------------------------------------------------------------------
            */
            Route::get('shift-rotation/monthly-roster', [ShiftChangeController::class, 'monthlyRoster']);
            Route::apiResource('shift-rotation', ShiftChangeController::class)->only(['index', 'store', 'update', 'show']);
            Route::post('shift-rotation/override', [ShiftChangeController::class, 'overrideShift']);
            Route::post('shift-rotation/swap', [ShiftChangeController::class, 'swapShift']);

            /*
            |--------------------------------------------------------------------------
            | Penalty Management
            |--------------------------------------------------------------------------
            */
            Route::post('penalties/bulk', [PenaltyController::class, 'storeBulk']);
            Route::post('penalties/bulk-upload', [PenaltyController::class, 'bulkUpload']);
            Route::apiResource('penalties', PenaltyController::class);

            /*
            |--------------------------------------------------------------------------
            | Payroll Management
            |--------------------------------------------------------------------------
            */

            Route::prefix('payroll')->group(function () {
                Route::get('/', [PayrollController::class, 'index']);
                Route::post('generate', [PayrollController::class, 'generate']);
                Route::get('{employeeId}/detail', [PayrollController::class, 'show']);
                Route::get('{employeeId}/penalties', [PayrollController::class, 'employeePenalties']);
                Route::patch('{id}/status', [PayrollController::class, 'updateStatus']);
                Route::patch('bulk-status', [PayrollController::class, 'bulkUpdateStatus']);
                Route::delete('{id}', [PayrollController::class, 'destroy']);
            });

            /*
            |--------------------------------------------------------------------------
            | Existing Vehicle Management Routes
            |--------------------------------------------------------------------------
            */

            // KEEP YOUR EXISTING VEHICLE ROUTES HERE
            // NO CHANGES REQUIRED
    
            /*
            |--------------------------------------------------------------------------
            | Shift Plan/Equipment Allocation/Employee deployment
            |--------------------------------------------------------------------------
            */

            Route::get('shift-plans/overview', [ShiftPlanController::class, 'overview']);
            Route::post('shift-plans/{id}/publish', [ShiftPlanController::class, 'publish']);
            Route::patch('shift-plans/{id}/status', [ShiftPlanController::class, 'updateStatus']);
            Route::apiResource('shift-plans', ShiftPlanController::class);
            Route::get('shift-plans/{shift_id}/summary', [ShiftPlanController::class, 'summary']);
            Route::get('shift-plans/{id}/view', [ShiftPlanController::class, 'view']);

            Route::prefix('shift-plans/{shift_plan_id}')->group(function () {
                Route::get('equipment/available', [EquipmentAllocationController::class, 'available']);
                Route::get('equipment', [EquipmentAllocationController::class, 'index']);
                Route::post('equipment', [EquipmentAllocationController::class, 'allocate']);
                Route::delete('equipment/{allocation_id}', [EquipmentAllocationController::class, 'destroy']);

                /*
                |--------------------------------------------------------------
                | Workforce Deployment
                |--------------------------------------------------------------
                */
                Route::post('workforce/load-relay', [WorkforceDeploymentController::class, 'loadRelay']);
                Route::get('workforce/available-employees', [WorkforceDeploymentController::class, 'availableEmployees']);
                Route::post('workforce/borrow', [WorkforceDeploymentController::class, 'borrow']);
                Route::get('workforce/summary', [WorkforceDeploymentController::class, 'summary']);
                Route::get('workforce', [WorkforceDeploymentController::class, 'index']);
                Route::delete('workforce/{deployment_id}', [WorkforceDeploymentController::class, 'destroy']);
            });

            /*
            |--------------------------------------------------------------------------
            | Shift Closure
            |--------------------------------------------------------------------------
            */
            Route::get('shift-plans/{shift_plan}/closure-summary', [ShiftClosureController::class, 'summary']);
            Route::post('shift-plans/{shift_plan}/close', [ShiftClosureController::class, 'close']);

            /*
            |--------------------------------------------------------------------------
            | Breakdown Management & Equipment Reliability
            |--------------------------------------------------------------------------
            */
            Route::prefix('maintenance/breakdowns')->group(function () {
                Route::post('import', [BreakdownController::class, 'import']);
                Route::get('/', [BreakdownController::class, 'index']);
                Route::get('{id}', [BreakdownController::class, 'show']);
                Route::post('/', [BreakdownController::class, 'store']);
                Route::match(['put', 'post'], '{id}', [BreakdownController::class, 'update']);
            });

            /*
            |--------------------------------------------------------------------------
            | Fuel Management
            |--------------------------------------------------------------------------
            */
            Route::prefix('fuel-entries')->group(function () {
                Route::post('import', [FuelEntryController::class, 'import']);
                Route::get('/', [FuelEntryController::class, 'index']);
                Route::post('/', [FuelEntryController::class, 'store']);
                Route::get('dashboard', [FuelEntryController::class, 'dashboard']);
                Route::get('performance', [FuelEntryController::class, 'performance']);
                Route::get('allocation-tracking', [FuelEntryController::class, 'allocationTracking']);
                Route::get('summary', [FuelEntryController::class, 'summary']);
                Route::get('{id}', [FuelEntryController::class, 'show']);
                Route::put('{id}', [FuelEntryController::class, 'update']);
            });

            /*
            |--------------------------------------------------------------------------
            | Service Management
            |--------------------------------------------------------------------------
            */
            Route::get('service-records/machine/{machine}/history', [ServiceRecordController::class, 'history']);
            Route::get('service-records/{serviceRecord}/audit-trail', [ServiceRecordController::class, 'auditTrail']);
            Route::apiResource('service-records', ServiceRecordController::class);

        });
    /*
    |--------------------------------------------------------------------------
    | Delay Analysis Management
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'role:super-admin,supervisor,site_incharge'])->prefix('admin/delays')->group(function () {
        Route::get('/', [DelayController::class, 'index']);
        Route::post('import', [DelayController::class, 'import']);
        Route::get('{id}', [DelayController::class, 'show']);
        Route::post('/', [DelayController::class, 'store']);
        Route::put('{id}', [DelayController::class, 'update']);
    });

});
