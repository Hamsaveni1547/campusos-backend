<?php

namespace App\Modules\AI\Assistant\Tools;

use App\Models\User;
use App\Modules\AI\Contracts\AssistantTool;
use App\Modules\Library\Models\LibraryLoan;
use stdClass;

class MyLibraryLoans implements AssistantTool
{
    public function name(): string
    {
        return 'my_library_loans';
    }

    public function description(): string
    {
        return 'Library books the person has borrowed: due dates, whether overdue, and fines.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function run(User $user, array $arguments): array
    {
        return ['books' => LibraryLoan::query()->with('copy.book')->where('user_id', $user->id)->whereNull('returned_on')->orderBy('due_on')->get()
            ->map(fn (LibraryLoan $l): array => ['book' => $l->copy->book->title, 'due_on' => $l->due_on->toDateString(), 'overdue' => $l->isOverdue(), 'fine' => (float) $l->fine])->values()->all()];
    }
}
