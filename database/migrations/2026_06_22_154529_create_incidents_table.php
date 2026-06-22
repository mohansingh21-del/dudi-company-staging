<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateIncidentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('incidents', function (Blueprint $table) {

            $table->id();

            $table->string('incident_no')->unique();

            $table->date('incident_date');

            $table->foreignId('shift_id')
                ->constrained('shifts')
                ->cascadeOnDelete();

            $table->foreignId('incident_type_id')
                ->constrained('incident_types')
                ->cascadeOnDelete();

            $table->enum('severity', [
                'LOW',
                'MEDIUM',
                'HIGH',
                'CRITICAL'
            ]);

            $table->enum('status', [
                'Reported',
                'Under Review',
                'Action Required',
                'Investigation Closed'
            ])->default('Reported');


            $table->foreignId('location_id')
                ->constrained('sites')
                ->cascadeOnDelete();


            $table->foreignId('person_involved_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();


            $table->text('incident_description');


            $table->text('action_taken');


            $table->text('preventive_measures')
                ->nullable();


            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('incidents');
    }
}
