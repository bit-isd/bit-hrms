<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_OPTIONS = [
        'lga' => [
            'Abeokuta North', 'Abeokuta South', 'Ado-Odo/Ota', 'Ewekoro', 'Ifo',
            'Ijebu East', 'Ijebu North', 'Ijebu North-East', 'Ijebu Ode', 'Ikenne',
            'Imeko Afon', 'Ipokia', 'Obafemi Owode', 'Odeda', 'Odogbolu',
            'Ogun Waterside', 'Remo North', 'Sagamu', 'Yewa North', 'Yewa South',
        ],
        'title' => ['Mr.', 'Mrs.', 'Miss', 'Engr.', 'Engr. (Mrs.)', 'Dr.'],
        'leave_type' => ['Annual Leave', 'Study Leave', 'Terminal Leave', 'Medical Leave', 'Maternity Leave', 'Paternity Leave', 'Casual Leave', 'Other'],
        'disciplinary_action' => [
            'Dismissal',
            'Removal on grounds of inefficiency',
            'Discharge on grounds of general unsuitability for further employment',
            'Reduction in rank',
            'Reduction in salary',
            'Withholding of increment',
            'Withholding of salary',
            'Surcharge',
            'Reprimand',
            'Interdiction',
            'Suspension',
            'Deferment of increment',
            'Formal letter of advice / Written warning',
            'Fine',
            'Termination of appointment',
            'Retirement in public interest',
        ],
    ];

    public function up(): void
    {
        foreach (['designations', 'employment_statuses'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'is_active')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->boolean('is_active')->default(true));
            }
        }

        Schema::create('lookup_options', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40);
            $table->string('value', 150);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['category', 'value']);
            $table->index(['category', 'is_active', 'sort_order']);
        });

        $now = now();
        foreach (self::DEFAULT_OPTIONS as $category => $values) {
            DB::table('lookup_options')->insert(array_map(fn (string $value, int $index) => [
                'category' => $category,
                'value' => $value,
                'sort_order' => $index + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $values, array_keys($values)));
        }

        Schema::table('audit_logs', fn (Blueprint $table) => $table->text('description')->nullable());
        Schema::table('staff', fn (Blueprint $table) => $table->timestamp('retirement_recorded_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('staff', fn (Blueprint $table) => $table->dropColumn('retirement_recorded_at'));
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropColumn('description'));
        Schema::dropIfExists('lookup_options');
        foreach (['designations', 'employment_statuses'] as $tableName) {
            if (Schema::hasColumn($tableName, 'is_active')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('is_active'));
            }
        }
    }
};
