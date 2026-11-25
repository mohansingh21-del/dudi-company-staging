<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every path that creates an attendance row now copies the worker's
     * standing `employees.place_of_employment` into the day's `place_of_work`,
     * but rows written before that (imports, bulk marking, auto-created absent
     * days) were left NULL and print as a blank column 4 in Form D. Fill them
     * in from the employee. Days that already carry a value were recorded
     * deliberately through the correction endpoint and are left untouched.
     *
     * @return void
     */
    public function up()
    {
        $table = $this->table();

        if (!Schema::hasColumn($table, 'place_of_work')) {
            return;
        }

        DB::table($table)
            ->join('employees', 'employees.id', '=', $table . '.employee_id')
            ->whereNull($table . '.place_of_work')
            ->whereNotNull('employees.place_of_employment')
            ->update([
                $table . '.place_of_work' => DB::raw('employees.place_of_employment'),
            ]);
    }

    /**
     * Not reversible: once backfilled there is no way to tell a filled-in day
     * from one that was recorded as that place to begin with.
     *
     * @return void
     */
    public function down()
    {
        //
    }

    /**
     * The table has shipped under both names; App\Models\AttendanceProcessed
     * resolves it the same way at runtime.
     */
    private function table(): string
    {
        return Schema::hasTable('attendance_processeds')
            ? 'attendance_processeds'
            : 'attendance_processed';
    }
};
