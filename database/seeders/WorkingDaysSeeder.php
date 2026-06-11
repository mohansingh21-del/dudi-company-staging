<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WorkingDaysSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $days = [
            'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'
        ];

        foreach ($days as $day) {
            \App\Models\WorkingDay::updateOrCreate(
                ['day' => $day],
                ['is_working' => ($day === 'Sunday' ? 0 : 1)]
            );
        }
    }
}
