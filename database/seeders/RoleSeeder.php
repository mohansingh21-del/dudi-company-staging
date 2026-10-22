<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run()
    {
        // Matched on slug only and never updated: existing rows (and their ids,
        // which employees.designation_id points at) are left exactly as they are.
        $roles = [
            ['name' => 'System-Administrator', 'slug' => 'super-admin'],
            ['name' => 'Worker', 'slug' => 'worker'],
            ['name' => 'Supervisor', 'slug' => 'supervisor'],
            ['name' => 'Project Manager', 'slug' => 'project-manager'],
            ['name' => 'Finance Admin', 'slug' => 'finance-admin'],
            ['name' => 'Site Incharge', 'slug' => 'site-incharge'],
            ['name' => 'Driver', 'slug' => 'driver'],

            ['name' => 'Accountant', 'slug' => 'accountant'],
            ['name' => 'Assistant Surveyor', 'slug' => 'assistant-surveyor'],
            ['name' => 'Asst. Mechanic', 'slug' => 'asst-mechanic'],
            ['name' => 'Contractor', 'slug' => 'contractor'],
            ['name' => 'Diesel Supervisor', 'slug' => 'diesel-supervisor'],
            ['name' => 'Driver HMV', 'slug' => 'driver-hmv'],
            ['name' => 'Driver LMV', 'slug' => 'driver-lmv'],
            ['name' => 'Electrician', 'slug' => 'electrician'],
            ['name' => 'Fitter', 'slug' => 'fitter'],
            ['name' => 'Head Accountant', 'slug' => 'head-accountant'],
            ['name' => 'Helper', 'slug' => 'helper'],
            ['name' => 'House Keeper', 'slug' => 'house-keeper'],
            ['name' => 'HR Assistant', 'slug' => 'hr-assistant'],
            ['name' => 'HR Manager', 'slug' => 'hr-manager'],
            ['name' => 'Mechanic', 'slug' => 'mechanic'],
            ['name' => 'Mechanical Manager', 'slug' => 'mechanical-manager'],
            ['name' => 'Mining Dozer', 'slug' => 'mining-dozer'],
            ['name' => 'Mining Excavator', 'slug' => 'mining-excavator'],
            ['name' => 'Mining Grader', 'slug' => 'mining-grader'],
            ['name' => 'Mining Loader', 'slug' => 'mining-loader'],
            ['name' => 'Office Assistant', 'slug' => 'office-assistant'],
            ['name' => 'Production Incharge', 'slug' => 'production-incharge'],
            ['name' => 'Purchaser', 'slug' => 'purchaser'],
            ['name' => 'Shift Incharge', 'slug' => 'shift-incharge'],
            ['name' => 'Store Incharge', 'slug' => 'store-incharge'],
            ['name' => 'Supervisor (Camp Incharge)', 'slug' => 'supervisor-camp-incharge'],
            ['name' => 'Survey HOD', 'slug' => 'survey-hod'],
            ['name' => 'Time Keeper', 'slug' => 'time-keeper'],
            ['name' => 'Welder', 'slug' => 'welder'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
