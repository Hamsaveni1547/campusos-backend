<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Student records with guardians, status history, documents and profile change requests.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('register_no', 40);
            $table->string('roll_no', 20)->nullable();
            $table->foreignId('programme_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('admission_type', 20);
            $table->string('category', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->date('admitted_on');
            $table->unsignedSmallInteger('current_term_number')->default(1);
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->string('nationality', 50)->nullable();
            $table->string('religion', 50)->nullable();
            $table->string('mother_tongue', 50)->nullable();
            $table->text('address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('id_proof_type', 30)->nullable();
            $table->text('id_proof_number')->nullable();
            $table->foreignId('photo_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->date('left_on')->nullable();
            $table->string('exit_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'register_no']);
            $table->unique(['institution_id', 'user_id']);
            $table->index(['institution_id', 'status']);
        });

        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('relation', 20);
            $table->string('name', 150);
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('occupation', 100)->nullable();
            $table->decimal('annual_income', 12, 2)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('student_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->date('effective_on');
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->foreignId('file_id')->constrained('files')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'document_type']);
        });

        Schema::create('profile_change_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->json('changes');
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_change_requests');
        Schema::dropIfExists('student_documents');
        Schema::dropIfExists('student_status_history');
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('students');
    }
};
