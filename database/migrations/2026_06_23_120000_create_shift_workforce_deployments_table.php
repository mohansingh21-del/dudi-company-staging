<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShiftWorkforceDeploymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('shift_workforce_deployments')) {
            return;
        }

        Schema::create('shift_workforce_deployments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('shift_plan_id');
            $table->unsignedBigInteger('employee_id');

            // Relay this employee is serving under FOR THIS SHIFT
            // Uses the same enum as employees.relay_shift
            $table->string('relay_shift', 20)->default('general');

            // Only set when is_borrowed = true — stores the employee's home relay
            $table->string('home_relay_shift', 20)->nullable();

            $table->unsignedBigInteger('assigned_machine_id')->nullable();

            // Denormalized snapshot of designation at deployment time
            $table->string('designation')->nullable();

            $table->boolean('is_borrowed')->default(false);

            // e.g. Leave Replacement, Additional Production Requirement,
            // Breakdown Support, Emergency Coverage, Overtime Support
            $table->string('borrowing_reason')->nullable();

            $table->unsignedBigInteger('borrowed_by')->nullable();
            $table->timestamp('borrowed_at')->nullable();

            $table->unsignedBigInteger('deployed_by')->nullable();

            // Soft state — don't hard delete, for audit trail
            $table->enum('status', ['active', 'removed'])->default('active');
            $table->string('removed_reason')->nullable();

            $table->timestamps();

            /*
            |------------------------------------------------------------------
            | Indexes
            |------------------------------------------------------------------
            */

            // Composite index for fast lookups (BR-SHFT-012 duplicate check)
            $table->index(['employee_id', 'shift_plan_id', 'status'], 'swd_emp_plan_status_idx');
            $table->index('shift_plan_id', 'swd_shift_plan_idx');
            $table->index('employee_id', 'swd_employee_idx');

            /*
            |------------------------------------------------------------------
            | Foreign Keys
            |------------------------------------------------------------------
            */

            $table->foreign('shift_plan_id')
                ->references('id')
                ->on('shift_plans')
                ->onDelete('cascade');

            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->onDelete('restrict');

            $table->foreign('assigned_machine_id')
                ->references('id')
                ->on('shift_equipment_allocations')
                ->onDelete('set null');

            $table->foreign('borrowed_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('deployed_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shift_workforce_deployments');
    }
}
