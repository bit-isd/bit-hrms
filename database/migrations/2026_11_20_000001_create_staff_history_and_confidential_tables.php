<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('title', 190);
            $table->date('occurred_on')->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('resumed_on')->nullable();
            $table->foreignId('from_grade_level_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $table->foreignId('to_grade_level_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $table->string('leave_type', 80)->nullable();
            $table->unsignedSmallInteger('leave_days')->nullable();
            $table->string('disciplinary_action', 120)->nullable();
            $table->text('reason')->nullable();
            $table->text('staff_response')->nullable();
            $table->text('action_taken')->nullable();
            $table->string('current_state', 120)->nullable();
            $table->string('training_type', 120)->nullable();
            $table->string('provider', 190)->nullable();
            $table->string('qualification_obtained', 190)->nullable();
            $table->string('circular_reference', 120)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['type', 'occurred_on']);
            $table->index(['staff_id', 'type', 'occurred_on']);
        });

        Schema::create('staff_performance_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->unsignedSmallInteger('rating_year');
            $table->decimal('rating', 3, 1);
            $table->text('comments')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['staff_id', 'rating_year']);
        });

        Schema::create('staff_confidential_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('title', 190);
            $table->string('category', 80)->default('CONFIDENTIAL');
            $table->string('storage_path', 500);
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 120);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['staff_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_confidential_documents');
        Schema::dropIfExists('staff_performance_ratings');
        Schema::dropIfExists('staff_events');
    }
};
