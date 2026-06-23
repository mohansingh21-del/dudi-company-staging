<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shift_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('published_by')->nullable()->after('created_by');
            $table->timestamp('published_at')->nullable()->after('published_by');

            $table->foreign('published_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shift_plans', function (Blueprint $table) {
            $table->dropForeign(['published_by']);
            $table->dropColumn(['published_by', 'published_at']);
        });
    }
};
