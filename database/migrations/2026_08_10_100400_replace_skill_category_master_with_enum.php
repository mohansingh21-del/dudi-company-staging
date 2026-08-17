<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The skill classification is a fixed set of four values, so it is held as
     * an enum on the employee rather than a lookup table.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('skill_category', [
                'highly_skilled',
                'skilled',
                'semi_skilled',
                'unskilled',
            ])->nullable()->after('designation_id');
        });

        // Carry over anything already assigned against the master.
        $map = [
            'HS' => 'highly_skilled',
            'S'  => 'skilled',
            'SS' => 'semi_skilled',
            'US' => 'unskilled',
        ];

        foreach ($map as $code => $value) {
            $id = DB::table('skill_categories')->where('code', $code)->value('id');

            if ($id) {
                DB::table('employees')->where('skill_category_id', $id)->update(['skill_category' => $value]);
            }
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['skill_category_id']);
            $table->dropColumn('skill_category_id');
        });

        Schema::dropIfExists('skill_categories');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::create('skill_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('skill_categories')->insert([
            ['code' => 'HS', 'name' => 'Highly Skilled', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'S',  'name' => 'Skilled',        'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SS', 'name' => 'Semi-Skilled',   'sort_order' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'US', 'name' => 'Unskilled',      'sort_order' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('skill_category_id')->nullable()->after('designation_id')
                ->constrained('skill_categories')->nullOnDelete();
        });

        $map = [
            'highly_skilled' => 'HS',
            'skilled'        => 'S',
            'semi_skilled'   => 'SS',
            'unskilled'      => 'US',
        ];

        foreach ($map as $value => $code) {
            $id = DB::table('skill_categories')->where('code', $code)->value('id');

            DB::table('employees')->where('skill_category', $value)->update(['skill_category_id' => $id]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('skill_category');
        });
    }
};
