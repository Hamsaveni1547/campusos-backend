<?php

use App\Modules\Library\Http\Controllers\LibraryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'resolve.institution', 'account.ready'])->group(function (): void {
    Route::get('library/options', [LibraryController::class, 'options'])->name('library.options');
    Route::get('library/books', [LibraryController::class, 'books'])->name('library.books.index');
    Route::post('library/books', [LibraryController::class, 'storeBook'])->name('library.books.store');
    Route::get('library/books/{book}', [LibraryController::class, 'show'])->name('library.books.show');
    Route::patch('library/books/{book}', [LibraryController::class, 'updateBook'])->name('library.books.update');
    Route::post('library/books/{book}/copies', [LibraryController::class, 'storeCopy'])->name('library.copies.store');
    Route::patch('library/copies/{copy}', [LibraryController::class, 'updateCopy'])->name('library.copies.update');

    Route::get('library/member', [LibraryController::class, 'memberLookup'])->name('library.member');
    Route::post('library/issue', [LibraryController::class, 'issue'])->name('library.issue');
    Route::post('library/return', [LibraryController::class, 'return'])->name('library.return');
    Route::post('library/loans/{loan}/renew', [LibraryController::class, 'renew'])->name('library.loans.renew');
    Route::post('library/loans/{loan}/fine', [LibraryController::class, 'settleFine'])->name('library.loans.fine');
    Route::get('library/loans', [LibraryController::class, 'loans'])->name('library.loans.index');
    Route::get('library/summary', [LibraryController::class, 'summary'])->name('library.summary');
    Route::get('users/{user}/library-dues', [LibraryController::class, 'userDues'])->name('users.library-dues');

    Route::get('me/library', [LibraryController::class, 'mine'])->name('me.library');
});
