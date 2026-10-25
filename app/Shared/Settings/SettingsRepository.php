<?php

namespace App\Shared\Settings;

use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Reads and writes settings. Resolution order: institution value →
 * platform value → registered default.
 */
class SettingsRepository
{
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private SettingsRegistry $registry,
        private CurrentInstitution $currentInstitution,
    ) {}

    /**
     * Get one setting for the given (or current) institution.
     */
    public function get(string $group, string $key, ?int $institutionId = null): mixed
    {
        return $this->group($group, $institutionId)[$key] ?? null;
    }

    /**
     * Resolved values for a whole group.
     *
     * @return array<string, mixed>
     */
    public function group(string $group, ?int $institutionId = null): array
    {
        $institutionId ??= $this->currentInstitution->id();
        $definitions = $this->registry->keys($group);

        $stored = Cache::remember(
            $this->cacheKey($group, $institutionId),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->loadStored($group, $institutionId),
        );

        $values = [];

        foreach ($definitions as $key => $definition) {
            $values[$key] = $this->cast(
                array_key_exists($key, $stored) ? $stored[$key] : $definition['default'],
                $definition['type'],
            );
        }

        return $values;
    }

    /**
     * Values only stored at platform level (institution_id NULL) plus defaults.
     *
     * @return array<string, mixed>
     */
    public function platformGroup(string $group): array
    {
        $definitions = $this->registry->keys($group);
        $stored = $this->loadStored($group, null);
        $values = [];

        foreach ($definitions as $key => $definition) {
            $values[$key] = $this->cast($stored[$key] ?? $definition['default'], $definition['type']);
        }

        return $values;
    }

    /**
     * Validate and save values for a group. Returns the changed keys with before/after.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function update(string $group, array $values, ?int $institutionId, bool $platformScope = false): array
    {
        $definitions = $this->registry->keys($group);

        $rules = [];
        foreach ($definitions as $key => $definition) {
            if (! $platformScope && ($definition['platform_only'] ?? false)) {
                continue;
            }

            $rules[$key] = array_merge(['sometimes'], $definition['rules']);

            if (isset($definition['item_rules'])) {
                $rules[$key.'.*'] = $definition['item_rules'];
            }
        }

        $values = array_intersect_key($values, $rules);

        $validated = Validator::make($values, $rules)->validate();

        $current = $platformScope ? $this->platformGroup($group) : $this->group($group, $institutionId);
        $changes = [];

        DB::transaction(function () use ($validated, $definitions, $group, $institutionId, $current, &$changes): void {
            foreach ($validated as $key => $value) {
                $value = $this->cast($value, $definitions[$key]['type']);

                if ($current[$key] === $value) {
                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['institution_id' => $institutionId, 'group' => $group, 'key' => $key],
                    ['value' => $value, 'updated_by' => Auth::id()],
                );

                $isSecret = $definitions[$key]['secret'] ?? false;
                $changes[$key] = [
                    'old' => $isSecret ? '********' : $current[$key],
                    'new' => $isSecret ? '********' : $value,
                ];
            }
        });

        $this->forget($group, $institutionId);

        return $changes;
    }

    /**
     * Group values with secret keys masked, for API output.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function mask(string $group, array $values): array
    {
        foreach ($this->registry->keys($group) as $key => $definition) {
            if (($definition['secret'] ?? false) && filled($values[$key] ?? null)) {
                $values[$key] = '********';
            }
        }

        return $values;
    }

    public function forget(string $group, ?int $institutionId): void
    {
        if ($institutionId === null) {
            // Platform defaults affect every institution, so clear every cached copy.
            Cache::increment($this->versionKey($group));

            return;
        }

        Cache::forget($this->cacheKey($group, $institutionId));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadStored(string $group, ?int $institutionId): array
    {
        $platform = Setting::query()->whereNull('institution_id')->where('group', $group)->pluck('value', 'key')->all();

        if ($institutionId === null) {
            return $platform;
        }

        $institution = Setting::query()->where('institution_id', $institutionId)->where('group', $group)->pluck('value', 'key')->all();

        return array_merge($platform, $institution);
    }

    private function cacheKey(string $group, ?int $institutionId): string
    {
        $version = (int) Cache::get($this->versionKey($group), 0);

        return sprintf('settings:%s:v%d:inst:%s', $group, $version, $institutionId ?? 'platform');
    }

    private function versionKey(string $group): string
    {
        return "settings:{$group}:version";
    }

    private function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return $type === 'array' ? [] : null;
        }

        return match ($type) {
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array' => array_values((array) $value),
            default => (string) $value,
        };
    }
}
