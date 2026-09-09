<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * 'reason' was the required description when penalties were only
     * fines. The recovery fields made 'particulars' the required
     * descriptor and relaxed 'reason' to nullable in the form request,
     * but the column was left NOT NULL — so any create that omits
     * reason dies on an integrity constraint before validation can
     * help.
     *
     * 'particulars' stays nullable at the database level on purpose:
     * penalties raised before this change have none, and the form
     * request already enforces it for everything created from now on.
     */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->text('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows created without a reason would block the reversal, so
        // give them one rather than failing the rollback.
        DB::table('penalties')
            ->whereNull('reason')
            ->update(['reason' => DB::raw('COALESCE(particulars, "")')]);

        Schema::table('penalties', function (Blueprint $table) {
            $table->text('reason')->nullable(false)->change();
        });
    }
};
