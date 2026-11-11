<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exam cycles created by the exam cell wait for the principal or admin to approve them.
     */
    public function up(): void
    {
        Schema::table('exam_cycles', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('registration_ends_on');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('approval_remarks', 500)->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('exam_cycles', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at', 'approval_remarks']);
        });
    }
};
