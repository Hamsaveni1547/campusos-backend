<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission cycles, seat matrix, applications with documents and status history.
     */
    public function up(): void
    {
        Schema::create('admission_cycles', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('status', 20)->default('draft');
            $table->date('opens_on');
            $table->date('closes_on');
            $table->decimal('application_fee', 10, 2)->default(0);
            $table->unsignedSmallInteger('offer_valid_days')->default(7);
            $table->json('required_documents')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'name']);
        });

        Schema::create('admission_seats', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->constrained()->restrictOnDelete();
            $table->string('quota', 20);
            $table->unsignedSmallInteger('total');
            $table->unsignedSmallInteger('filled')->default(0);
            $table->decimal('min_percentage', 5, 2)->nullable();
            $table->decimal('admission_fee', 10, 2)->default(0);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['admission_cycle_id', 'programme_id', 'quota']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_seat_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('application_no', 40)->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('category', 30)->nullable();
            $table->json('personal')->nullable();
            $table->json('guardian')->nullable();
            $table->json('academic')->nullable();
            $table->json('entrance')->nullable();
            $table->decimal('qualifying_percentage', 5, 2)->nullable();
            $table->boolean('seat_held')->default(false);
            $table->string('remarks', 1000)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('offer_expires_at')->nullable();
            $table->timestamp('admitted_at')->nullable();
            $table->string('register_no', 40)->nullable();
            $table->decimal('fee_amount', 10, 2)->nullable();
            $table->string('fee_reference', 100)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'application_no']);
            $table->unique(['institution_id', 'register_no']);
            $table->index(['institution_id', 'status']);
            $table->index(['admission_cycle_id', 'user_id']);
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->foreignId('file_id')->constrained('files')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'document_type']);
        });

        Schema::create('application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('remarks', 1000)->nullable();
            $table->foreignId('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_status_history');
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('admission_seats');
        Schema::dropIfExists('admission_cycles');
    }
};
