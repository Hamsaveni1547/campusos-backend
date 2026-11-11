<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modules switched on/off per institution, and built-in roles whose permissions the platform admin edited.
     */
    public function up(): void
    {
        Schema::create('institution_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('module', 40);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['institution_id', 'module']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_customised')->default(false)->after('is_department_scoped');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_customised');
        });
        Schema::dropIfExists('institution_modules');
    }
};
