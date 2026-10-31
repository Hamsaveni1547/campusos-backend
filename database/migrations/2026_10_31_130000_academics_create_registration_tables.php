<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Term registration: a student's courses for a term (core from their section plus chosen electives).
     */
    public function up(): void
    {
        Schema::create('term_registrations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('term_number');
            $table->string('status', 20)->default('submitted');
            $table->decimal('total_credits', 5, 1)->default(0);
            $table->string('remarks', 1000)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'term_id']);
            $table->index(['institution_id', 'term_id', 'status']);
        });

        Schema::create('course_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->timestamps();

            $table->unique(['term_registration_id', 'course_offering_id'], 'course_registrations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_registrations');
        Schema::dropIfExists('term_registrations');
    }
};
