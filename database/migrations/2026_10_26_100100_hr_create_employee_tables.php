<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('category', 20);
            $table->unsignedSmallInteger('rank')->default(100);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'name']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('employee_code', 30);
            $table->string('staff_type', 20);
            $table->string('employment_type', 20);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('joined_on');
            $table->date('contract_ends_on')->nullable();
            $table->date('left_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('exit_reason')->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('highest_qualification', 100)->nullable();
            $table->string('specialization', 150)->nullable();
            $table->decimal('prior_experience_years', 4, 1)->default(0);
            $table->text('address')->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'employee_code']);
            $table->unique(['institution_id', 'user_id']);
            $table->index(['institution_id', 'department_id', 'status']);
        });

        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('degree', 100);
            $table->string('specialization', 150)->nullable();
            $table->string('university', 200)->nullable();
            $table->unsignedSmallInteger('year_of_passing')->nullable();
            $table->string('grade', 30)->nullable();
            $table->foreignId('certificate_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_qualifications');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('designations');
    }
};
