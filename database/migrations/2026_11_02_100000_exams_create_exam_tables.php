<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Examinations: cycles, the exam timetable, per-course registrations (eligibility, script code,
     * external marks and result), per-student term results, and revaluation requests.
     */
    public function up(): void
    {
        $base = function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
        };
        $authors = function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        };

        Schema::create('exam_cycles', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('type', 20);
            $table->string('status', 30)->default('draft');
            $table->date('registration_ends_on')->nullable();
            $table->foreignId('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $authors($table);
        });

        Schema::create('exam_schedules', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('exam_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->date('exam_date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->decimal('max_marks', 6, 2)->default(100);
            $authors($table);

            $table->unique(['exam_cycle_id', 'course_id']);
        });

        Schema::create('exam_registrations', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('exam_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('eligibility', 20)->default('pending');
            $table->json('eligibility_reasons')->nullable();
            $table->string('override_reason', 500)->nullable();
            $table->foreignId('overridden_by')->nullable();
            $table->string('script_code', 20)->nullable();
            $table->string('attendance', 10)->nullable();
            $table->decimal('external_marks', 6, 2)->nullable();
            $table->foreignId('marks_entered_by')->nullable();
            $table->decimal('internal_marks', 6, 2)->nullable();
            $table->decimal('internal_out_of', 6, 2)->nullable();
            $table->decimal('total_percent', 5, 2)->nullable();
            $table->string('grade', 5)->nullable();
            $table->decimal('grade_points', 4, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->unsignedTinyInteger('revision')->default(0);
            $authors($table);

            $table->unique(['exam_cycle_id', 'student_id', 'course_id'], 'exam_registrations_unique');
            $table->unique(['exam_cycle_id', 'script_code'], 'exam_registrations_script_unique');
        });

        Schema::create('exam_results', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('exam_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('credits_registered', 5, 1)->default(0);
            $table->decimal('credits_earned', 5, 1)->default(0);
            $table->decimal('sgpa', 4, 2)->nullable();
            $table->decimal('cgpa', 4, 2)->nullable();
            $table->unsignedSmallInteger('backlogs')->default(0);
            $authors($table);

            $table->unique(['exam_cycle_id', 'student_id']);
        });

        Schema::create('revaluation_requests', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('exam_registration_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->decimal('old_marks', 6, 2)->nullable();
            $table->decimal('new_marks', 6, 2)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $authors($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revaluation_requests');
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('exam_registrations');
        Schema::dropIfExists('exam_schedules');
        Schema::dropIfExists('exam_cycles');
    }
};
