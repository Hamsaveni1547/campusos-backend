<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mentoring: mentor assignments (with history), notes, concerns with action plans, and meetings.
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

        Schema::create('mentor_assignments', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->string('reason', 300)->nullable();
            $authors($table);

            $table->index(['employee_id', 'to_date']);
            $table->index(['student_id', 'to_date']);
        });

        Schema::create('mentoring_notes', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('visibility', 20)->default('shared');
            $authors($table);
        });

        Schema::create('student_concerns', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);
            $table->text('description');
            $table->text('action_plan')->nullable();
            $table->date('review_on')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('outcome')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $authors($table);
        });

        Schema::create('mentoring_meetings', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->json('student_ids');
            $table->string('status', 20)->default('scheduled');
            $table->dateTime('scheduled_at')->nullable();
            $table->string('agenda', 500);
            $table->text('minutes')->nullable();
            $table->json('attended_ids')->nullable();
            $authors($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentoring_meetings');
        Schema::dropIfExists('student_concerns');
        Schema::dropIfExists('mentoring_notes');
        Schema::dropIfExists('mentor_assignments');
    }
};
