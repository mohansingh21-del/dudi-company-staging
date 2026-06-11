<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $role = \App\Models\Role::where('slug', 'super-admin')->first();

        if ($role) {
            $user = \App\Models\User::updateOrCreate(
                ['email' => 'mychoice00p@gmail.com'],
                [
                    'password' => Hash::make('admin@123'),
                    'is_active' => true,
                ]
            );

            $user->roles()->sync([$role->id]);
        }
    }
}
