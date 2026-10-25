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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->nullable()->index();
            $table->nullableMorphs('attachable');
            $table->string('purpose', 64)->index();
            $table->string('classification', 16)->default('internal');
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64)->index();
            $table->foreignId('uploaded_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('file_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('action', 16);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable();
            $table->string('sequence_key', 64);
            $table->string('scope', 64)->default('');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['institution_id', 'sequence_key', 'scope']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable();
            $table->string('group', 64);
            $table->string('key', 64);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'group', 'key']);
        });

        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->nullable()->index();
            $table->string('type', 64);
            $table->foreignId('file_id')->constrained('files');
            $table->string('status', 32)->default('pending')->index();
            $table->json('options')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('errors')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imports');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('file_access_logs');
        Schema::dropIfExists('files');
    }
};
