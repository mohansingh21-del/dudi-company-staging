<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn leave_types into the master of Form E's four statutory blocks.
     *
     * The register always prints the same four blocks, so this becomes a fixed
     * four-row table keyed by `register_group`. The only thing an admin edits is
     * the annual quota (allowed_days) and the active flag.
     *
     * The two rows already live are remapped rather than replaced, so the 22
     * existing rows in `leaves` keep pointing at a valid leave type.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // Nullable, so any legacy row this migration cannot place keeps NULL
            // instead of colliding on the unique index (MySQL allows many NULLs).
            $table->enum('register_group', ['compensatory_rest', 'earned', 'medical', 'other'])
                ->nullable()
                ->after('leave_category');
        });

        // "Paid Leave" carried the annual quota, so it becomes Earned Leave;
        // "Unpaid Leave" becomes the Other block. Resolved in PHP because MySQL
        // cannot subquery the same table it is updating.
        $paidId = DB::table('leave_types')->where('leave_category', 'paid')->min('id');
        $unpaidId = DB::table('leave_types')->where('leave_category', 'unpaid')->min('id');

        if ($paidId) {
            DB::table('leave_types')->where('id', $paidId)->update(['register_group' => 'earned']);
        }

        if ($unpaidId) {
            DB::table('leave_types')->where('id', $unpaidId)->update(['register_group' => 'other']);
        }

        // Anything left over is not one of the four blocks — keep the row so its
        // leaves stay valid, but take it off the register.
        DB::table('leave_types')->whereNull('register_group')->update(['is_active' => 0]);

        // compensatory_rest and medical are paid; other is unpaid.
        $defaults = [
            'compensatory_rest' => ['name' => 'Compensatory Rest', 'leave_category' => 'paid', 'allowed_days' => 0],
            'earned' => ['name' => 'Earned Leave', 'leave_category' => 'paid', 'allowed_days' => 12],
            'medical' => ['name' => 'Medical Leave', 'leave_category' => 'paid', 'allowed_days' => 0],
            'other' => ['name' => 'Other Leave', 'leave_category' => 'unpaid', 'allowed_days' => 0],
        ];

        foreach ($defaults as $group => $attributes) {
            // Fall back to matching on the canonical name: down() drops the
            // register_group column, so after a rollback the rows this
            // migration created come back with no group. Without this, a
            // re-run would insert a second copy of each block.
            $existing = DB::table('leave_types')->where('register_group', $group)->first()
                ?: DB::table('leave_types')
                    ->whereNull('register_group')
                    ->where('name', $attributes['name'])
                    ->first();

            if ($existing) {
                // Keep the quota the client already configured, but take the
                // name and category from the block — the row is a statutory
                // block now, so "Paid Leave" would misname the Earned column.
                DB::table('leave_types')->where('id', $existing->id)->update([
                    'name' => $attributes['name'],
                    'register_group' => $group,
                    'leave_category' => $attributes['leave_category'],
                    'is_active' => 1,
                    'updated_at' => now(),
                ]);
                continue;
            }

            DB::table('leave_types')->insert($attributes + [
                'register_group' => $group,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('leave_types', function (Blueprint $table) {
            $table->unique('register_group');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropUnique(['register_group']);
            $table->dropColumn('register_group');
        });
    }
};
