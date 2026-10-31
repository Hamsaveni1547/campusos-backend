<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal assessment: course outcomes, scheme groups, assessments and marks,
     * internal mark sheets per class, corrections, assignments and submissions.
     */
    public function up(): void
    {
        $base = function (Blueprint $table, bool $ulid = true): void {
            $table->id();

            if ($ulid) {
                $table->ulid('ulid')->unique();
            }

            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
        };
        $authors = function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        };

        Schema::create('course_outcomes', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('description', 500);
            $table->string('bloom_level', 20)->nullable();
            $authors($table);

            $table->unique(['course_id', 'code']);
        });

        Schema::create('assessment_groups', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedTinyInteger('best_of')->nullable();
            $table->decimal('weight', 6, 2);
            $table->unsignedTinyInteger('position')->default(0);
            $authors($table);
        });

        Schema::create('assessments', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('assessment_group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 20);
            $table->date('held_on')->nullable();
            $table->decimal('max_marks', 6, 2);
            $table->json('outcome_codes')->nullable();
            $authors($table);
        });

        Schema::create('assessment_marks', function (Blueprint $table) use ($base) {
            $base($table, false);
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('marks', 6, 2)->nullable();
            $table->string('status', 20)->default('present');
            $table->foreignId('entered_by')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id']);
            $table->index('student_id');
        });

        Schema::create('internal_mark_sheets', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('offering_section_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            $table->json('rules')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->foreignId('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('remarks', 1000)->nullable();
            $authors($table);
        });

        Schema::create('internal_marks', function (Blueprint $table) use ($base) {
            $base($table, false);
            $table->foreignId('internal_mark_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('computed', 6, 2);
            $table->decimal('final', 6, 2);
            $table->json('breakdown');
            $table->timestamps();

            $table->unique(['internal_mark_sheet_id', 'student_id']);
        });

        Schema::create('mark_corrections', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('assessment_mark_id')->constrained()->cascadeOnDelete();
            $table->decimal('requested_marks', 6, 2)->nullable();
            $table->string('requested_status', 20)->default('present');
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $authors($table);
        });

        Schema::create('assignments', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->text('instructions')->nullable();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->timestamp('due_at');
            $table->decimal('max_marks', 6, 2);
            $table->boolean('allow_late')->default(true);
            $authors($table);
        });

        Schema::create('assignment_submissions', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->text('text')->nullable();
            $table->timestamp('submitted_at');
            $table->boolean('is_late')->default(false);
            $table->decimal('marks', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('evaluated_by')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $authors($table);

            $table->unique(['assignment_id', 'student_id']);
        });
    }

    public function down(): void
    {
        foreach (['assignment_submissions', 'assignments', 'mark_corrections', 'internal_marks', 'internal_mark_sheets', 'assessment_marks', 'assessments', 'assessment_groups', 'course_outcomes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
