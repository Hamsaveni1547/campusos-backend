<?php

namespace App\Modules\AI\Assistant\Tools;

use App\Models\User;
use App\Modules\AI\Contracts\AssistantTool;
use App\Modules\Students\Models\Student;
use stdClass;

/**
 * Base for tools about the signed-in student's own records.
 */
abstract class StudentTool implements AssistantTool
{
    public function availableTo(User $user): bool
    {
        return $this->student($user) !== null;
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    protected function student(User $user): ?Student
    {
        return Student::query()->where('user_id', $user->id)->first();
    }
}
