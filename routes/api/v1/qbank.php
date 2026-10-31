<?php

use App\Modules\QuestionBank\Http\Controllers\QuestionBankController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('qbank/options', [QuestionBankController::class, 'options'])->name('qbank.options');

    // Questions
    Route::get('courses/{course}/questions', [QuestionBankController::class, 'questions'])->name('questions.index');
    Route::post('courses/{course}/questions', [QuestionBankController::class, 'storeQuestion'])->name('questions.store');
    Route::get('courses/{course}/question-coverage', [QuestionBankController::class, 'coverage'])->name('questions.coverage');
    Route::patch('questions/{question}', [QuestionBankController::class, 'updateQuestion'])->name('questions.update');
    Route::post('questions/{question}/retire', [QuestionBankController::class, 'retireQuestion'])->name('questions.retire');

    // Blueprints
    Route::get('courses/{course}/paper-blueprints', [QuestionBankController::class, 'blueprints'])->name('paper-blueprints.index');
    Route::post('courses/{course}/paper-blueprints', [QuestionBankController::class, 'storeBlueprint'])->name('paper-blueprints.store');
    Route::patch('paper-blueprints/{blueprint}', [QuestionBankController::class, 'updateBlueprint'])->name('paper-blueprints.update');
    Route::delete('paper-blueprints/{blueprint}', [QuestionBankController::class, 'destroyBlueprint'])->name('paper-blueprints.destroy');
    Route::get('courses/{course}/upcoming-exams', [QuestionBankController::class, 'exams'])->name('qbank.upcoming-exams');
    Route::post('paper-blueprints/{blueprint}/generate', [QuestionBankController::class, 'generate'])->name('paper-blueprints.generate');

    // Papers
    Route::get('question-papers', [QuestionBankController::class, 'papers'])->name('question-papers.index');
    Route::get('question-papers/{paper}', [QuestionBankController::class, 'show'])->name('question-papers.show');
    Route::delete('question-papers/{paper}', [QuestionBankController::class, 'destroy'])->name('question-papers.destroy');
    Route::get('question-papers/{paper}/content', [QuestionBankController::class, 'content'])->name('question-papers.content');
    Route::get('question-papers/{paper}/access-logs', [QuestionBankController::class, 'logs'])->name('question-papers.logs');
    Route::post('question-papers/{paper}/{action}', [QuestionBankController::class, 'transition'])->whereIn('action', ['submit', 'review', 'seal'])->name('question-papers.transition');
});
