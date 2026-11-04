<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Library: titles, physical copies with accession numbers, and loans (with overdue fines).
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

        Schema::create('library_books', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->string('title', 300);
            $table->string('authors', 300);
            $table->string('isbn', 20)->nullable();
            $table->string('publisher', 150)->nullable();
            $table->string('edition', 50)->nullable();
            $table->unsignedSmallInteger('published_year')->nullable();
            $table->string('subject', 100)->nullable();
            $table->string('call_number', 50)->nullable();
            $authors($table);

            $table->index(['institution_id', 'title']);
            $table->index(['institution_id', 'isbn']);
        });

        Schema::create('library_copies', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('library_book_id')->constrained()->cascadeOnDelete();
            $table->string('accession_no', 30);
            $table->string('shelf', 50)->nullable();
            $table->string('status', 20)->default('available');
            $authors($table);

            $table->unique(['institution_id', 'accession_no']);
        });

        Schema::create('library_loans', function (Blueprint $table) use ($base, $authors) {
            $base($table);
            $table->foreignId('library_copy_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('member_type', 20);
            $table->date('issued_on');
            $table->date('due_on');
            $table->date('returned_on')->nullable();
            $table->unsignedTinyInteger('renewals')->default(0);
            $table->decimal('fine', 10, 2)->default(0);
            $table->string('fine_status', 20)->default('none');
            $table->string('fine_note', 300)->nullable();
            $authors($table);

            $table->index(['user_id', 'returned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_loans');
        Schema::dropIfExists('library_copies');
        Schema::dropIfExists('library_books');
    }
};
