<?php

namespace App\Console\Commands;

use App\Models\Holiday;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds active holidays that share a (site_id, holiday_date) and keeps one.
 *
 * Read-only by default. `--fix` is what actually writes, and all it writes is
 * is_active = 0 on the losers - nothing is deleted, so a bad call is one UPDATE
 * away from being undone.
 *
 * This has to be run and applied BEFORE the unique index migration: the index
 * only constrains active rows, and it cannot be created while active
 * duplicates exist.
 */
class DedupeHolidaysCommand extends Command
{
    protected $signature = 'holidays:dedupe
                            {--fix : Apply the plan — deactivate the losers. Without this the command only reports}
                            {--suspicious : Also list active holidays whose names look like test entries}
                            {--site= : Limit to one site id}';

    protected $description = 'Report (and optionally deactivate) duplicate holidays sharing a site and date';

    public function handle()
    {
        $apply = (bool) $this->option('fix');

        $groups = $this->duplicateGroups();

        if ($groups->isEmpty()) {
            $this->info('No active duplicate holidays found.');
        } else {
            $this->reportDuplicates($groups, $apply);
        }

        if ($this->option('suspicious')) {
            $this->line('');
            $this->reportSuspicious();
        }

        return 0;
    }

    /**
     * Active rows grouped by the key the unique index will enforce.
     *
     * site_id is nullable and NULL never equals NULL in SQL, so general
     * holidays are grouped under a COALESCE'd 0 - exactly the key the index
     * uses - or they would each look unique and slip through the cleanup only
     * to break the migration.
     */
    protected function duplicateGroups()
    {
        $query = Holiday::query()
            ->active()
            ->orderBy('holiday_date')
            ->orderBy('id');

        if ($this->option('site') !== null) {
            $query->where('site_id', (int) $this->option('site'));
        }

        return $query->get()->groupBy(function ($holiday) {
            return sprintf(
                '%s|%s',
                $holiday->site_id === null ? '0' : $holiday->site_id,
                $holiday->holiday_date->format('Y-m-d')
            );
        })->filter(function ($rows) {
            return $rows->count() > 1;
        });
    }

    protected function reportDuplicates($groups, bool $apply): void
    {
        $this->warn(sprintf(
            '%d duplicate (site_id, date) group(s) found, %d row(s) total.',
            $groups->count(),
            $groups->flatten()->count()
        ));
        $this->line('');

        $losers = [];
        $rows = [];

        foreach ($groups as $key => $group) {
            [$siteKey, $date] = explode('|', $key);

            $sorted = $this->rank($group);
            $keep = $sorted->shift();

            $rows[] = [
                $siteKey === '0' ? 'ALL SITES' : ('site ' . $siteKey),
                $date,
                'KEEP  #' . $keep->id,
                $keep->holiday_name,
                $keep->holiday_type ?: '-',
                optional($keep->created_at)->format('Y-m-d H:i'),
            ];

            foreach ($sorted as $drop) {
                $losers[] = $drop->id;

                $rows[] = [
                    '',
                    '',
                    'DROP  #' . $drop->id,
                    $drop->holiday_name,
                    $drop->holiday_type ?: '-',
                    optional($drop->created_at)->format('Y-m-d H:i'),
                ];
            }
        }

        $this->table(['Scope', 'Date', 'Action', 'Name', 'Type', 'Created'], $rows);

        if (! $apply) {
            $this->line('');
            $this->info(sprintf(
                'Dry run — nothing changed. %d row(s) would be set is_active = 0.',
                count($losers)
            ));
            $this->comment('Re-run with --fix to apply.');

            return;
        }

        DB::table('holidays')->whereIn('id', $losers)->update([
            'is_active' => 0,
            'updated_at' => now(),
        ]);

        $this->line('');
        $this->info(sprintf('%d duplicate row(s) deactivated.', count($losers)));
    }

    /**
     * Order a duplicate group best-first. The head is kept, the rest are
     * deactivated.
     *
     * A proper name beats a test-looking one, because the row somebody named
     * "Diwali" is the one the register should print and "rest day1" is not.
     * Failing that, a row with a holiday_type beats one without - it was filled
     * in more completely. The oldest row wins the remaining ties: it is the one
     * most likely already referenced by whatever was generated from it.
     */
    protected function rank($group)
    {
        // One zero-padded sort key rather than sortBy()'s array form: that form
        // treats a closure as a two-argument comparator, not a key extractor,
        // so the per-field closures would have sorted on a truth value.
        return $group->sortBy(function ($holiday) {
            $createdAt = $holiday->created_at ? $holiday->created_at->timestamp : PHP_INT_MAX;

            return sprintf(
                '%d|%d|%020d|%020d',
                Holiday::isTestLikeName($holiday->holiday_name) ? 1 : 0,
                $holiday->holiday_type ? 0 : 1,
                $createdAt,
                $holiday->id
            );
        }, SORT_STRING)->values();
    }

    /**
     * Active rows whose names read like somebody testing the form.
     *
     * Reported only. These are judgement calls — "Test Match Holiday" is a
     * false positive — so the command never touches them and the admin decides.
     */
    protected function reportSuspicious(): void
    {
        $suspects = Holiday::query()
            ->active()
            ->with('site')
            ->orderBy('holiday_date')
            ->get()
            ->filter(function ($holiday) {
                return Holiday::isTestLikeName($holiday->holiday_name);
            });

        if ($suspects->isEmpty()) {
            $this->info('No test-looking holiday names found.');

            return;
        }

        $this->warn(sprintf('%d active holiday(s) look like test entries:', $suspects->count()));

        $this->table(
            ['ID', 'Date', 'Name', 'Type', 'Site', 'Created'],
            $suspects->map(function ($holiday) {
                return [
                    $holiday->id,
                    $holiday->holiday_date->format('Y-m-d'),
                    $holiday->holiday_name,
                    $holiday->holiday_type ?: '-',
                    $holiday->site_id === null ? 'ALL SITES' : optional($holiday->site)->site_name,
                    optional($holiday->created_at)->format('Y-m-d H:i'),
                ];
            })->all()
        );

        $this->comment('Reported only — review these by hand, then deactivate via the holiday status endpoint.');
    }
}
