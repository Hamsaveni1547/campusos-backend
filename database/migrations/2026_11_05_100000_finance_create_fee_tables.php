<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fees: heads, structures with items, demands (what a student owes) with items, concessions,
     * payments with numbered receipts, and how each payment is applied to demands.
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

        Schema::create('fee_heads', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->string('status', 20)->default('active');
            $authors($table);

            $table->unique(['institution_id', 'code']);
        });

        Schema::create('fee_structures', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->string('name', 150);
            $table->foreignId('programme_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('admission_year')->nullable();
            $table->string('quota', 20)->nullable();
            $table->unsignedTinyInteger('term_number')->nullable();
            $table->date('due_on');
            $table->string('status', 20)->default('active');
            $authors($table);
        });

        Schema::create('fee_structure_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_structure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_head_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
        });

        Schema::create('fee_demands', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_structure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->date('due_on');
            $table->decimal('amount', 12, 2);
            $table->decimal('concession', 12, 2)->default(0);
            $table->decimal('paid', 12, 2)->default(0);
            $table->string('status', 20)->default('open');
            $table->string('cancel_reason', 500)->nullable();
            $authors($table);

            $table->index(['student_id', 'status']);
        });

        Schema::create('fee_demand_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_demand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_head_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
        });

        Schema::create('fee_concessions', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('fee_demand_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $authors($table);
        });

        Schema::create('fee_payments', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_no', 40);
            $table->decimal('amount', 12, 2);
            $table->string('mode', 20);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->string('status', 20)->default('valid');
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $authors($table);

            $table->unique(['institution_id', 'receipt_no']);
        });

        Schema::create('fee_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_demand_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
        });
    }

    public function down(): void
    {
        foreach (['fee_payment_allocations', 'fee_payments', 'fee_concessions', 'fee_demand_items', 'fee_demands', 'fee_structure_items', 'fee_structures', 'fee_heads'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
