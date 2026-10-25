<?php

namespace App\Shared\Authorization;

/**
 * Implemented by each module to declare its permissions.
 * Names follow `module.resource.action`.
 */
interface PermissionProvider
{
    public static function module(): string;

    /**
     * @return array<string, array{description: string, sensitive?: bool}>
     */
    public static function permissions(): array;
}
