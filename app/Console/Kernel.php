<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\RotateShiftsCommand::class,
        \App\Console\Commands\RemoveExpiredBorrowedEmployeesCommand::class,
        \App\Console\Commands\ResetShiftTestData::class,
        \App\Console\Commands\SyncVecvFuelCommand::class,
    ];
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('roster:rotate')->sundays()->at('00:00');
        $schedule->command('borrowed:cleanup')->everyMinute();

        // Interval is config-driven: shift-boundary precision is capped by it,
        // so it can be relaxed to hourly for reporting or tightened for control.
        $schedule->command('vecv:sync-fuel')
            ->cron(config('vecv.fuel_sync_cron'))
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}