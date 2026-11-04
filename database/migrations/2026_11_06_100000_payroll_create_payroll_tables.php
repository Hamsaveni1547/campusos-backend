<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payroll: pay components, each employee's salary, monthly runs, one-off adjustments and payslips.
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

        Schema::create('pay_components', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('kind', 20);
            $table->string('calculation', 20);
            $table->decimal('percent', 6, 3)->nullable();
            $table->decimal('max_amount', 12, 2)->nullable();
            $table->boolean('affected_by_lop')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 20)->default('active');
            $authors($table);

            $table->unique(['institution_id', 'code']);
        });

        Schema::create('employee_salaries', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2)->nullable();
            $authors($table);

            $table->unique(['employee_id', 'pay_component_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->char('month', 7);
            $table->string('status', 20)->default('draft');
            $table->foreignId('computed_by')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('finalized_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->date('paid_on')->nullable();
            $authors($table);

            $table->unique(['institution_id', 'month']);
        });

        Schema::create('payslips', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('days_in_month');
            $table->decimal('lop_days', 4, 1)->default(0);
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);
            $table->json('lines')->nullable();
            $authors($table);

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('label', 100);
            $table->decimal('amount', 12, 2);
            $authors($table);
        });
    }

    public function down(): void
    {
        foreach (['payroll_adjustments', 'payslips', 'payroll_runs', 'employee_salaries', 'pay_components'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
