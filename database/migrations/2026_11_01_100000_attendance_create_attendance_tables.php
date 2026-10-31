<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendance sessions (one class meeting), a record per student, corrections and condonations.
     */
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_section_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->foreignId('time_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->time('starts_at')->nullable();
            $table->string('component', 20)->default('theory');
            $table->string('notes', 500)->nullable();
            $table->foreignId('marked_by')->nullable();
            $table->timestamp('marked_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['offering_section_id', 'date', 'time_slot_id'], 'attendance_sessions_unique');
            $table->index(['institution_id', 'date']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['attendance_session_id', 'student_id'], 'attendance_records_unique');
            $table->index(['student_id', 'attendance_session_id'], 'attendance_records_student_idx');
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->string('requested_status', 20);
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'status']);
        });

        Schema::create('attendance_condonations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_section_id')->constrained()->cascadeOnDelete();
            $table->decimal('percentage_at_grant', 5, 2);
            $table->string('reason', 500);
            $table->string('fee_reference', 100)->nullable();
            $table->foreignId('granted_by')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'offering_section_id'], 'attendance_condonations_unique');
        });
    }

    public function down(): void
    {
        foreach (['attendance_condonations', 'attendance_corrections', 'attendance_records', 'attendance_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
