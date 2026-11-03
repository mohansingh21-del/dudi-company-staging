<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\RotateShiftsCommand::class,
        \App\Console\Commands\RemoveExpiredBorrowedEmployeesCommand::class,
        \App\Console\Commands\ResetShiftTestData::class,
        \App\Console\Commands\SyncVecvFuelCommand::class,
        \App\Console\Commands\SyncVecvLocationCommand::class,
        \App\Console\Commands\SyncVecvServiceHistoryCommand::class,
        \App\Console\Commands\SyncVecvAlertsCommand::class,
        \App\Console\Commands\ProbeVecvAlertsCommand::class,
        \App\Console\Commands\SyncVecvAllCommand::class,
        \App\Console\Commands\SyncTruckConnectCommand::class,
        \App\Console\Commands\RelinkTelematicsReadingsCommand::class,
        \App\Console\Commands\AdvanceTelematicsRefreshCommand::class,
        \App\Console\Commands\RegisterTelematicsChassisCommand::class,
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
        //
        // Every task is registered through inProcess() rather than
        // $schedule->command(). command() runs each task as a child process via
        // proc_open, which shared hosting disables - every task then failed
        // before starting, and roster:rotate silently skipped whole weeks.
        // Do not add runInBackground() either; it needs proc_open as well.

        // Midnight as the new week's first day (Monday) begins, on site time
        // whatever the server clock is set to.
        $this->inProcess($schedule, 'roster:rotate')->mondays()->at('00:00')->timezone('Asia/Kolkata');
        $this->inProcess($schedule, 'borrowed:cleanup')->everyMinute();

        // Carries an in-progress fleet refresh to its next feed.
        //
        // A full refresh spans minutes because VECV throttles by API key, so
        // something has to move the cycle between windows. Where exec is
        // available the refresh button launches its own detached pass and this
        // does nothing; on shared hosting, where exec is disabled, this is what
        // keeps "press once and poll" working instead of making the browser
        // post once per feed.
        //
        // Idle runs cost nothing - with no cycle in progress it returns without
        // touching the network. It also records the heartbeat the API uses to
        // tell whether a waiting cycle will move on its own.
        $this->inProcess($schedule, 'telematics:advance-refresh')
            ->everyMinute()
            ->withoutOverlapping();

        /*
        | Register any newly seen telematics chassis as a Dumper machine in
        | equipment_names, so the VECV syncs can resolve their readings.
        |
        | Reads the stored readings tables only - it does not call the vendor
        | APIs - so it is cheap and safe to run even with the VECV schedulers
        | above disabled. 03:30 keeps it clear of the telemetry slots
        | (:00/:15/:30/:45, :01/:16/:31/:46) and the 03:10 service-history run.
        */
        $this->inProcess($schedule, 'telematics:register-chassis')
            ->dailyAt('03:30')
            ->withoutOverlapping();

        /*
        |----------------------------------------------------------------------
        | VECV telematics - SCHEDULED SYNCS DISABLED (2026-09-02)
        |----------------------------------------------------------------------
        |
        | Turned off at the client's request to keep background load off the
        | server. Telemetry is now pulled on demand instead, by the dashboard's
        | POST /api/v1/dashboard/fleet/refresh button.
        |
        | What that costs, so the trade-off is not rediscovered later:
        |
        |   - The dashboard is only as fresh as the last button press. If
        |     nobody opens it overnight, the morning's first view shows
        |     yesterday's readings.
        |   - Nothing accumulates a time series any more. Anything derived by
        |     differencing two readings - distance or fuel per shift, machine
        |     utilisation - cannot be built from on-demand fetches, because the
        |     getlivedata endpoints only ever return "last known now" and the
        |     gaps between clicks are unrecoverable.
        |   - Service history (workshop job cards) stops updating altogether.
        |
        | The commands themselves are still registered above and can be run by
        | hand at any time:
        |
        |   php artisan vecv:sync-fuel
        |   php artisan vecv:sync-location
        |   php artisan vecv:sync-service-history
        |
        | To restore, uncomment below - and register them through inProcess()
        | without runInBackground(), or they hit the proc_open failure above.
        | The intervals are config-driven, so a lighter cadence is a .env change rather than a code change - e.g.
        | VECV_FUEL_SYNC_CRON="0 * * * *" for hourly, which is ample for
        | reporting totals at 24 calls a day.
        |
        */

        // $schedule->command('vecv:sync-fuel')
        //     ->cron(config('vecv.fuel_sync_cron'))
        //     ->withoutOverlapping()
        //     ->runInBackground();

        // $schedule->command('vecv:sync-location')
        //     ->cron(config('vecv.location_sync_cron'))
        //     ->withoutOverlapping()
        //     ->runInBackground();

        // $schedule->command('vecv:sync-service-history')
        //     ->cron(config('vecv.service_history_sync_cron'))
        //     ->withoutOverlapping()
        //     ->runInBackground();
    }

    /**
     * Schedule an artisan command to run inside the schedule:run process.
     *
     * A closure event calls the command directly instead of spawning
     * "php artisan ..." through proc_open, so it works where proc_open is
     * disabled. The name doubles as the mutex key, which withoutOverlapping()
     * requires on closure events.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @param  string  $command
     * @return \Illuminate\Console\Scheduling\CallbackEvent
     */
    private function inProcess(Schedule $schedule, string $command)
    {
        return $schedule->call(function () use ($command) {
            Artisan::call($command);
        })->name($command);
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