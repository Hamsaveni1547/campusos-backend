<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff leave: types, yearly balances per employee, and applications.
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

        Schema::create('leave_types', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->string('code', 10);
            $table->string('name', 100);
            $table->decimal('days_per_year', 5, 1)->default(0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('allow_half_day')->default(true);
            $table->string('status', 20)->default('active');
            $authors($table);

            $table->unique(['institution_id', 'code']);
        });

        Schema::create('leave_balances', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('allotted', 5, 1);
            $authors($table);

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::create('leave_applications', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('half_day')->default(false);
            $table->decimal('days', 5, 1);
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $authors($table);

            $table->index(['employee_id', 'from_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_applications');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
    }
};
