<?php

namespace App\Shared\Tenancy;

use App\Modules\Institution\Models\Institution;
use App\Shared\Exceptions\BusinessRuleException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holds the institution (tenant) the current request or job is working in.
 */
class CurrentInstitution
{
    private ?Institution $institution = null;

    public function __construct(private PermissionRegistrar $permissionRegistrar) {}

    public function set(?Institution $institution): void
    {
        $this->institution = $institution;
        $this->permissionRegistrar->setPermissionsTeamId($institution?->id);
    }

    public function get(): ?Institution
    {
        return $this->institution;
    }

    public function id(): ?int
    {
        return $this->institution?->id;
    }

    public function has(): bool
    {
        return $this->institution !== null;
    }

    /**
     * Get the current institution or fail when none is resolved.
     */
    public function require(): Institution
    {
        if ($this->institution === null) {
            throw new BusinessRuleException('No institution is selected for this request.', 'INSTITUTION_REQUIRED', 403);
        }

        return $this->institution;
    }

    /**
     * Run a callback with a different institution selected, then restore the previous one.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(?Institution $institution, callable $callback): mixed
    {
        $previous = $this->institution;
        $this->set($institution);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }
}
