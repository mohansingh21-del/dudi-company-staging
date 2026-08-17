<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recovery_uploads', function (Blueprint $table) {
            $table->string('file_hash', 64)
                ->nullable()
                ->unique()
                ->after('file_name');
        });
    }

    public function down(): void
    {
        Schema::table('recovery_uploads', function (Blueprint $table) {
            $table->dropUnique([
                'file_hash',
            ]);

            $table->dropColumn('file_hash');
        });
    }
};
