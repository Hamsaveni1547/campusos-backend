<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feedback surveys (anonymous answers; completions recorded separately) and complaints.
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

        Schema::create('surveys', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->string('title', 200);
            $table->string('description', 1000)->nullable();
            $table->string('kind', 20);
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audience', 20)->default('students');
            $table->date('opens_on');
            $table->date('closes_on');
            $table->string('status', 20)->default('draft');
            $authors($table);
        });

        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('text', 500);
            $table->string('type', 10);
            $table->json('options')->nullable();
            $table->boolean('required')->default(true);
        });

        // No user, no time of day: answers cannot be traced back to a person.
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_section_id')->nullable()->constrained()->nullOnDelete();
            $table->date('submitted_on');
        });

        Schema::create('survey_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_response_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_question_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('choice', 200)->nullable();
            $table->text('text')->nullable();
        });

        Schema::create('survey_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_section_id')->nullable()->constrained()->nullOnDelete();

            $table->unique(['survey_id', 'user_id', 'offering_section_id'], 'survey_completions_unique');
        });

        Schema::create('complaint_categories', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->string('name', 100);
            $table->unsignedSmallInteger('sla_hours')->default(72);
            $table->boolean('is_restricted')->default(false);
            $table->string('status', 20)->default('active');
            $authors($table);
        });

        Schema::create('complaints', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('complaint_category_id')->constrained()->restrictOnDelete();
            $table->string('subject', 200);
            $table->text('description');
            $table->boolean('is_anonymous')->default(false);
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_to')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $authors($table);

            $table->index(['institution_id', 'status']);
        });

        Schema::create('complaint_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['complaint_messages', 'complaints', 'complaint_categories', 'survey_completions', 'survey_answers', 'survey_responses', 'survey_questions', 'surveys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
