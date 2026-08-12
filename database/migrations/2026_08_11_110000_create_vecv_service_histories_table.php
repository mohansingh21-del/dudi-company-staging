<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVecvServiceHistoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Dealer workshop job cards pulled from VECV. Deliberately NOT merged into
     * service_records: that table is this project's own record of servicing
     * performed at the mine (ticket numbers, sites, downtime, checklists,
     * spare parts, soft deletes). These are third-party records of work done
     * at an Eicher dealership, with no overlapping columns and no local
     * authorship. The vecv_ prefix keeps the provenance unmistakable.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vecv_service_histories', function (Blueprint $table) {
            $table->id();

            // Natural key from the vendor. Unlike the telemetry tables these
            // rows are mutable - a job card is amended after it opens, most
            // obviously when an invoice is attached once the work is billed -
            // so ingest updates in place on this key rather than inserting a
            // new row per poll.
            $table->string('job_card_number', 32)->unique();

            // vehicleChassisNo. Indexed rather than unique: a vehicle
            // accumulates many job cards over its life.
            $table->string('chassis_number', 32)->index();

            // Resolved at ingest. Null when the chassis is not yet registered
            // as a machine - records are still stored so nothing is lost.
            $table->unsignedBigInteger('equipment_name_id')->nullable();

            // registrationNo. Populated here even though the telemetry feeds
            // return regNo empty, so it is a real value worth keeping.
            $table->string('registration_no', 32)->nullable();

            $table->string('dealer_name', 191)->nullable();
            $table->string('model_description', 191)->nullable();

            // ordertypedescription - e.g. "Running Repair". The vendor spells
            // this key entirely lowercase, unlike every other field.
            $table->string('order_type', 64)->nullable();

            // Absent until the job card is billed, which is the main reason
            // records have to be re-read rather than written once.
            $table->string('invoice_number', 32)->nullable();

            // Delivered as a string ("351166"); cast on ingest.
            $table->decimal('odometer', 12, 2)->nullable();

            // Cost breakdown. lubeTranValue / labourTranValue /
            // partsValueAmount arrive as numbers, totalCostCust as a string.
            $table->decimal('lube_value', 12, 2)->nullable();
            $table->decimal('labour_value', 12, 2)->nullable();
            $table->decimal('parts_value', 12, 2)->nullable();
            $table->decimal('total_cost_customer', 12, 2)->nullable();

            // Stored as a date, not a timestamp. The vendor sends
            // "2023-08-22T00:00:00.000+00:00" - midnight UTC standing in for a
            // plain calendar date. Converting that to IST would be a false
            // precision, so only the date component is kept.
            $table->date('job_card_open_date')->nullable()->index();

            $table->json('raw')->nullable();

            $table->timestamps();

            $table->foreign('equipment_name_id')
                ->references('id')
                ->on('equipment_names')
                ->onDelete('set null');

            // Named explicitly: the generated name for the second of these
            // would be 65 characters, one over the MySQL identifier limit.
            $table->index(['chassis_number', 'job_card_open_date'], 'vsh_chassis_opened_index');
            $table->index(['equipment_name_id', 'job_card_open_date'], 'vsh_machine_opened_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vecv_service_histories');
    }
}
