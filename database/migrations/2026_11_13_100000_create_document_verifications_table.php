<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per issued PDF document (receipt, payslip, hall ticket, grade card), so anyone holding a
     * printout can check it with the short code printed on it.
     */
    public function up(): void
    {
        Schema::create('document_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('reference', 60);
            $table->string('title', 200);
            $table->string('issued_to', 150);
            $table->json('summary')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['institution_id', 'type', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verifications');
    }
};
