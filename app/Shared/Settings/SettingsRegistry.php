<?php

namespace App\Shared\Settings;

use InvalidArgumentException;

/**
 * Catalogue of setting groups. Modules register their groups in their service
 * provider. Each key has a type, a default and validation rules.
 *
 * @phpstan-type SettingDefinition array{type: 'int'|'bool'|'string'|'array', default: mixed, rules: array<int, mixed>, label: string, item_rules?: array<int, mixed>, secret?: bool, platform_only?: bool}
 */
class SettingsRegistry
{
    /**
     * @var array<string, array{label: string, keys: array<string, SettingDefinition>}>
     */
    private array $groups = [];

    /**
     * @param  array<string, SettingDefinition>  $keys
     */
    public function register(string $group, string $label, array $keys): void
    {
        $this->groups[$group] = ['label' => $label, 'keys' => $keys];
    }

    public function has(string $group): bool
    {
        return isset($this->groups[$group]);
    }

    /**
     * @return array<string, array{label: string, keys: array<string, SettingDefinition>}>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function keys(string $group): array
    {
        if (! $this->has($group)) {
            throw new InvalidArgumentException("Unknown settings group [{$group}].");
        }

        return $this->groups[$group]['keys'];
    }

    /**
     * @return SettingDefinition
     */
    public function definition(string $group, string $key): array
    {
        $keys = $this->keys($group);

        if (! isset($keys[$key])) {
            throw new InvalidArgumentException("Unknown setting [{$group}.{$key}].");
        }

        return $keys[$key];
    }

    public function label(string $group): string
    {
        return $this->groups[$group]['label'] ?? $group;
    }
}
