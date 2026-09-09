<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            ['name' => 'System-Administrator', 'slug' => 'super-admin'],
            ['name' => 'Worker', 'slug' => 'worker'],
            ['name' => 'Supervisor', 'slug' => 'supervisor'],
            ['name' => 'Project Manager', 'slug' => 'project-manager'],
            ['name' => 'Finance Admin', 'slug' => 'finance-admin'],
            ['name' => 'Site Incharge', 'slug' => 'site-incharge'],
            ['name' => 'Driver', 'slug' => 'driver'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
