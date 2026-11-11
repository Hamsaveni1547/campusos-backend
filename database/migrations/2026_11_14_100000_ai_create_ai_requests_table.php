<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every AI call: which feature, who, which provider and model, tokens, time and outcome.
     * Prompts and answers are not stored (they may contain personal text).
     */
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 40);
            $table->string('provider', 20)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 20);
            $table->string('error', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['institution_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
