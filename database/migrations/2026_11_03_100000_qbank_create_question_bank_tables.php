<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Question bank (encrypted question bodies), paper blueprints, generated question papers with
     * their questions, sealed encrypted payloads and an access log.
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

        Schema::create('questions', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('unit')->nullable();
            $table->string('type', 20);
            $table->decimal('marks', 5, 2);
            $table->string('co_code', 10)->nullable();
            $table->string('bloom_level', 20)->nullable();
            $table->string('difficulty', 10);
            $table->text('encrypted_body');
            $table->char('body_hash', 64);
            $table->string('status', 20)->default('active');
            $authors($table);

            $table->index(['course_id', 'status', 'type', 'marks']);
            $table->unique(['course_id', 'body_hash']);
        });

        Schema::create('paper_blueprints', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedSmallInteger('duration_minutes')->default(180);
            $table->json('sections');
            $table->unsignedTinyInteger('avoid_recent_papers')->default(2);
            $authors($table);
        });

        Schema::create('question_papers', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('paper_blueprint_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('set_code', 5);
            $table->unsignedInteger('seed');
            $table->string('status', 20)->default('draft');
            $table->string('remarks', 1000)->nullable();
            $table->foreignId('submitted_by')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('sealed_by')->nullable();
            $table->timestamp('sealed_at')->nullable();
            $table->longText('encrypted_payload')->nullable();
            $table->text('wrapped_key')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $authors($table);
        });

        Schema::create('question_paper_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_paper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('section');
            $table->unsignedSmallInteger('position');

            $table->unique(['question_paper_id', 'question_id']);
        });

        Schema::create('paper_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_paper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('action', 30);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_access_logs');
        Schema::dropIfExists('question_paper_items');
        Schema::dropIfExists('question_papers');
        Schema::dropIfExists('paper_blueprints');
        Schema::dropIfExists('questions');
    }
};
