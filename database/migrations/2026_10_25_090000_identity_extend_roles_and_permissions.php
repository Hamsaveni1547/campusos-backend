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
        Schema::table('roles', function (Blueprint $table) {
            $table->ulid('ulid')->nullable()->unique()->after('id');
            $table->string('display_name')->nullable()->after('name');
            $table->text('description')->nullable()->after('display_name');
            $table->boolean('is_system')->default(false)->after('description');
            $table->boolean('is_active')->default(true)->after('is_system');
            $table->boolean('is_department_scoped')->default(false)->after('is_active');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->string('module', 64)->nullable()->after('name')->index();
            $table->string('description')->nullable()->after('module');
            $table->boolean('is_sensitive')->default(false)->after('description');
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable()->index();
            $table->string('reason')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'user_id', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_assignments');

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['module', 'description', 'is_sensitive']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['ulid', 'display_name', 'description', 'is_system', 'is_active', 'is_department_scoped']);
        });
    }
};
