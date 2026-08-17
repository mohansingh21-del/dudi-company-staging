<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recovery_upload_rows', function (Blueprint $table) {
            $table->json('raw_values')
                ->nullable()
                ->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('recovery_upload_rows', function (Blueprint $table) {
            $table->dropColumn('raw_values');
        });
    }
};
