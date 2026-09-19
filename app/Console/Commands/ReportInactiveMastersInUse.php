<?php

namespace App\Console\Commands;

use App\Services\MasterUsageGuard;
use Illuminate\Console\Command;

/**
 * Finds masters that were switched off while something live was still using
 * them — the damage done before MasterUsageGuard started refusing those
 * deactivations.
 *
 * Every row listed here is a record whose edit screen opens with a blank
 * picker, because dropdown feeds only serve active rows. Reports only: what to
 * do about each one is a call for whoever owns the data, so nothing is written.
 */
class ReportInactiveMastersInUse extends Command
{
    protected $signature = 'masters:inactive-in-use';

    protected $description = 'List master records that are switched off but still in live use';

    public function handle(MasterUsageGuard $guard): int
    {
        $rows = [];

        foreach ($guard->guardedMasters() as $class) {
            $inactive = $class::where('is_active', 0)->get();

            foreach ($inactive as $master) {
                $blockers = $guard->blockers($master);

                if (empty($blockers)) {
                    continue;
                }

                $rows[] = [
                    class_basename($class),
                    $master->getKey(),
                    $this->nameOf($master),
                    implode(', ', array_map(function ($blocker) {
                        return $blocker['count'] . ' ' . $blocker['label'];
                    }, $blockers)),
                ];
            }
        }

        if (empty($rows)) {
            $this->info('Nothing to report: no switched-off master is still in live use.');

            return self::SUCCESS;
        }

        $this->warn(count($rows) . ' master record(s) are switched off but still in use:');
        $this->newLine();
        $this->table(['Master', 'ID', 'Name', 'Still used by'], $rows);
        $this->newLine();
        $this->line('Each of these leaves a blank dropdown on the records using it.');
        $this->line('Switching one back on is enough to fix it; nothing was changed by this command.');

        return self::SUCCESS;
    }

    /**
     * Masters do not agree on what their label column is called.
     */
    protected function nameOf($master): string
    {
        foreach (['name', 'shift_name', 'holiday_name', 'incident_type', 'breakdown_type', 'delay_category', 'title'] as $column) {
            if (!empty($master->{$column})) {
                return (string) $master->{$column};
            }
        }

        return '—';
    }
}
