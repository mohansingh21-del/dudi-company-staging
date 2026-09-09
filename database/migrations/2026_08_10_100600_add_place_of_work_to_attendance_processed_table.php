<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Column 4 of the Attendance Register (Form D), which the Mines Act requires
     * only for mines. Held per attendance day rather than on the employee: a
     * worker can be moved between opencast and surface within the same month,
     * and the register has to reflect where they actually were.
     *
     * @return void
     */
    public function up()
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->enum('place_of_work', [
                'underground',
                'opencast',
                'surface',
            ])->nullable()->after('shift_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('place_of_work');
        });
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
