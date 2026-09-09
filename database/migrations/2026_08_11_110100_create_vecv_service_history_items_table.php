<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVecvServiceHistoryItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * The jobCardLineItems array nested inside each service history record -
     * one row per part fitted or job performed on a job card.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vecv_service_history_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('vecv_service_history_id');

            // rowId, a vendor-supplied UUID. Unique, so an item is never
            // duplicated even though the parent is re-read on every run.
            $table->uuid('row_id')->unique();

            $table->text('job_description')->nullable();

            // Observed empty on every item so far; normalised to null.
            $table->string('job_id_description', 191)->nullable();

            // Free text written by the workshop, length not documented.
            $table->text('action')->nullable();
            $table->text('observation')->nullable();

            // matnr / matnrType - e.g. "ID301999" / "Spare Parts".
            $table->string('material_code', 64)->nullable();
            $table->string('material_type', 64)->nullable();

            // jobTypeBezei - e.g. "AMC". German-derived vendor key
            // (Bezeichnung); renamed here since it is just a label.
            $table->string('job_type', 32)->nullable();

            $table->decimal('part_qty', 10, 2)->nullable();

            // partTotalAmt is ex-GST, partTotalAmtInGst is inclusive. Both are
            // kept: the pair is the only place the tax component appears, and
            // the observed values (152.54 vs 180.00) do not derive from one
            // another by any fixed rate.
            $table->decimal('part_total_amount', 12, 2)->nullable();
            $table->decimal('part_total_amount_with_gst', 12, 2)->nullable();

            $table->json('raw')->nullable();

            $table->timestamps();

            $table->foreign('vecv_service_history_id')
                ->references('id')
                ->on('vecv_service_histories')
                ->onDelete('cascade');

            $table->index('vecv_service_history_id', 'vshi_history_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vecv_service_history_items');
    }
}
