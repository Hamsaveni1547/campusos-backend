<?php

namespace App\Modules\AI\Contracts;

use App\Models\User;

/**
 * Something the assistant can look up for the signed-in person (read only).
 * A tool decides itself whether it applies to the person and only returns their own data
 * or data their permissions already allow.
 */
interface AssistantTool
{
    /** snake_case name the model calls. */
    public function name(): string;

    /** When to use it, for the model. */
    public function description(): string;

    /**
     * JSON schema of the arguments.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    public function availableTo(User $user): bool;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function run(User $user, array $arguments): array;
}
