<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Courses with syllabus, curriculum versions, term offerings with sections and faculty,
     * syllabus coverage and course materials.
     */
    public function up(): void
    {
        $tenant = function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
        };
        $authors = function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        };

        Schema::create('courses', function (Blueprint $table) use ($tenant, $authors) {
            $tenant($table);
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('title', 200);
            $table->string('type', 20);
            $table->decimal('credits', 4, 1);
            $table->unsignedTinyInteger('lecture_hours')->default(0);
            $table->unsignedTinyInteger('tutorial_hours')->default(0);
            $table->unsignedTinyInteger('practical_hours')->default(0);
            $table->string('regulation', 50)->nullable();
            $table->text('description')->nullable();
            $table->json('textbooks')->nullable();
            $table->string('status', 20)->default('active');
            $authors($table);

            $table->unique(['institution_id', 'code']);
        });

        Schema::create('syllabus_units', function (Blueprint $table) use ($tenant, $authors) {
            $tenant($table);
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->string('title', 200);
            $table->unsignedSmallInteger('hours')->default(0);
            $authors($table);
        });

        Schema::create('syllabus_topics', function (Blueprint $table) use ($tenant, $authors) {
            $tenant($table);
            $table->foreignId('syllabus_unit_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->decimal('hours', 4, 1)->default(1);
            $table->unsignedSmallInteger('position')->default(0);
            $authors($table);
        });

        Schema::create('curricula', function (Blueprint $table) use ($tenant, $authors) {
            $tenant($table);
            $table->foreignId('programme_id')->constrained()->restrictOnDelete();
            $table->string('regulation', 50);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->string('remarks', 1000)->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $authors($table);

            $table->unique(['programme_id', 'regulation', 'version']);
        });

        Schema::create('curriculum_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained('curricula')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('term_number');
            $table->string('category', 30)->default('core');
            $table->boolean('is_elective')->default(false);
            $table->timestamps();

            $table->unique(['curriculum_id', 'course_id']);
        });

        Schema::create('course_offerings', function (Blueprint $table) use ($tenant, $authors) {
            $tenant($table);
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_id')->nullable()->constrained('curricula')->nullOnDelete();
            $table->boolean('is_elective')->default(false);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('status', 20)->default('active');
            $authors($table);

            $table->unique(['course_id', 'term_id']);
        });

        Schema::create('offering_sections', function (Blueprint $table) use ($tenant, $authors) {
            $tenant($table);
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $authors($table);

            $table->unique(['course_offering_id', 'section_id']);
        });

        Schema::create('faculty_allocations', function (Blueprint $table) use ($tenant, $authors) {
            $tenant($table);
            $table->foreignId('offering_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('component', 20);
            $table->unsignedTinyInteger('weekly_hours');
            $authors($table);

            $table->unique(['offering_section_id', 'employee_id', 'component'], 'faculty_allocations_unique');
        });

        Schema::create('topic_coverage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_topic_id')->constrained()->cascadeOnDelete();
            $table->date('covered_on');
            $table->string('notes', 500)->nullable();
            $table->foreignId('covered_by')->nullable();
            $table->timestamps();

            $table->unique(['offering_section_id', 'syllabus_topic_id']);
        });

        Schema::create('course_materials', function (Blueprint $table) use ($tenant, $authors) {
            $tenant($table);
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('url', 500)->nullable();
            $table->boolean('is_published')->default(true);
            $authors($table);
        });
    }

    public function down(): void
    {
        foreach (['course_materials', 'topic_coverage', 'faculty_allocations', 'offering_sections', 'course_offerings', 'curriculum_courses', 'curricula', 'syllabus_topics', 'syllabus_units', 'courses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
