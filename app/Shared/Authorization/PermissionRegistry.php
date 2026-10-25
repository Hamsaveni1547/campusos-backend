<?php

namespace App\Shared\Authorization;

use Illuminate\Support\Str;

/**
 * Collects permission definitions from every module listed in
 * config('campusos.permission_providers').
 */
class PermissionRegistry
{
    /**
     * @return array<string, array{module: string, description: string, sensitive: bool}>
     */
    public function all(): array
    {
        $definitions = [];

        /** @var class-string<PermissionProvider> $provider */
        foreach (config('campusos.permission_providers', []) as $provider) {
            foreach ($provider::permissions() as $name => $definition) {
                $definitions[$name] = [
                    'module' => $provider::module(),
                    'description' => $definition['description'],
                    'sensitive' => $definition['sensitive'] ?? false,
                ];
            }
        }

        ksort($definitions);

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * Expand patterns such as `identity.*` or `identity.users.view` to permission names.
     *
     * @param  array<int, string>  $patterns
     * @return array<int, string>
     */
    public function expand(array $patterns): array
    {
        $names = $this->names();

        return collect($patterns)
            ->flatMap(fn (string $pattern): array => array_values(array_filter($names, fn (string $name): bool => Str::is($pattern, $name))))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
