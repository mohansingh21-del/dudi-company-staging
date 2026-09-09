<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecoveryUploadsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('recovery_uploads', function (Blueprint $table) {
            $table->id();

            $table->string('document_id')->unique();
            $table->string('file_name');

            $table->foreignId('uploaded_by')
                ->constrained('users');

            $table->enum('status', ['failed', 'success'])
                ->default('failed');

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);

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
        Schema::dropIfExists('recovery_uploads');
    }
}
