<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;

class ResetShiftTestData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:reset-shift-data
                            {--force : Force run without confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset all shift-flow test data in correct FK-dependency order (child tables first). Only runs in local/staging.';

    /**
     * Tables to clean in FK-dependency order (children first, parent last).
     * Each entry is [table_name, optional_fk_column_for_audit].
     *
     * @var array
     */
    protected $tables = [
        // Audit / log tables (leaf nodes)
        'dispatch_trip_audits',
        'fuel_entry_audit_logs',
        'delay_audit_logs',

        // Operational child tables
        'dispatch_trips',
        'delays',
        'breakdown_tickets',
        'fuel_entries',

        // Shift child tables
        'shift_workforce_deployments',
        'shift_equipment_allocations',

        // Parent table
        'shift_plans',
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // ── PRODUCTION GUARD ──────────────────────────────────────────
        if (!App::environment('local', 'staging')) {
            $this->error('🚫  ABORTED — This command may only run in "local" or "staging" environments.');
            $this->error('   Current environment: ' . App::environment());
            return 1;
        }

        $this->info('Environment: ' . App::environment());
        $this->newLine();

        // ── AUDIT: show current row counts ────────────────────────────
        $this->info('📊  Current row counts (before reset):');
        $auditData = [];
        foreach ($this->tables as $table) {
            $count = DB::table($table)->count();
            $auditData[] = [$table, $count];
        }
        $this->table(['Table', 'Row Count'], $auditData);
        $this->newLine();

        // ── CONFIRMATION ──────────────────────────────────────────────
        if (!$this->option('force')) {
            if (!$this->confirm('⚠️  This will DELETE all rows from the tables listed above and reset AUTO_INCREMENT. Continue?')) {
                $this->warn('Aborted by user.');
                return 0;
            }
        }

        // ── RESET EXECUTION ───────────────────────────────────────────
        try {
            // Temporarily disable FK checks so ordering is the only safeguard
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($this->tables as $table) {
                $deleted = DB::table($table)->count();
                DB::table($table)->delete();
                DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
                $this->line("  ✅  {$table}: {$deleted} rows deleted, AUTO_INCREMENT reset.");
            }

            DB::statement('SET FOREIGN_KEY_CHECKS = 1');

            $this->newLine();
            $this->info('🎉  All shift-flow test data has been reset successfully.');

            return 0;
        } catch (\Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');

            $this->error('❌  Reset FAILED.');
            $this->error('   Error: ' . $e->getMessage());

            return 1;
        }
    }
}
