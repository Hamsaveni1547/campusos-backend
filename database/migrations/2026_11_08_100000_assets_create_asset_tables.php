<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Labs and assets: asset register, maintenance requests and room/lab bookings.
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

        Schema::create('assets', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->string('tag', 30);
            $table->string('name', 150);
            $table->string('category', 50);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('make', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->date('purchased_on')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('vendor', 150)->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('status', 20)->default('working');
            $authors($table);

            $table->unique(['institution_id', 'tag']);
        });

        Schema::create('maintenance_requests', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('medium');
            $table->string('status', 20)->default('open');
            $table->string('assigned_to', 150)->nullable();
            $table->text('resolution')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $authors($table);

            $table->index(['institution_id', 'status']);
        });

        Schema::create('room_bookings', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('purpose', 300);
            $table->string('status', 20)->default('booked');
            $authors($table);

            $table->index(['room_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_bookings');
        Schema::dropIfExists('maintenance_requests');
        Schema::dropIfExists('assets');
    }
};
