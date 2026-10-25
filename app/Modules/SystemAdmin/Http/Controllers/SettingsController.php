<?php

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Modules\SystemAdmin\Authorization\SystemAdminPermissions;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Settings\SettingsRegistry;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Institution settings. Platform administrators edit the defaults under /platform/settings.
 *
 * @tags Settings
 */
class SettingsController extends Controller
{
    public function __construct(
        private SettingsRegistry $registry,
        private SettingsRepository $settings,
        private CurrentInstitution $currentInstitution,
        private SecurityLogger $securityLogger,
    ) {}

    /**
     * Setting groups with their keys, labels and current values.
     */
    public function index(): JsonResponse
    {
        Gate::authorize(SystemAdminPermissions::SettingsView);

        return response()->json(['data' => collect($this->registry->groups())->keys()->map(fn (string $group): array => $this->describe($group, false))->values()]);
    }

    public function show(string $group): JsonResponse
    {
        Gate::authorize(SystemAdminPermissions::SettingsView);
        $this->assertGroup($group);

        return response()->json(['data' => $this->describe($group, false)]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        Gate::authorize(SystemAdminPermissions::SettingsManage);
        $this->assertGroup($group);

        $changes = $this->settings->update($group, $request->all(), $this->currentInstitution->require()->id);
        $this->logChanges($group, $changes, false);

        return response()->json(['data' => $this->describe($group, false)]);
    }

    /**
     * Platform defaults for every institution (platform administrators).
     */
    public function platformIndex(): JsonResponse
    {
        return response()->json(['data' => collect($this->registry->groups())->keys()->map(fn (string $group): array => $this->describe($group, true))->values()]);
    }

    public function platformUpdate(Request $request, string $group): JsonResponse
    {
        $this->assertGroup($group);

        $changes = $this->currentInstitution->runAs(null, fn () => $this->settings->update($group, $request->all(), null, platformScope: true));
        $this->logChanges($group, $changes, true);

        return response()->json(['data' => $this->describe($group, true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $group, bool $platform): array
    {
        $values = $platform
            ? $this->settings->platformGroup($group)
            : $this->settings->group($group, $this->currentInstitution->require()->id);

        $values = $this->settings->mask($group, $values);
        $keys = [];

        foreach ($this->registry->keys($group) as $key => $definition) {
            if (! $platform && ($definition['platform_only'] ?? false)) {
                continue;
            }

            $keys[] = [
                'key' => $key,
                'label' => $definition['label'],
                'type' => $definition['type'],
                'default' => ($definition['secret'] ?? false) ? null : $definition['default'],
                'value' => $values[$key],
                'secret' => $definition['secret'] ?? false,
            ];
        }

        return ['group' => $group, 'label' => $this->registry->label($group), 'settings' => $keys];
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    private function logChanges(string $group, array $changes, bool $platform): void
    {
        if ($changes === []) {
            return;
        }

        activity('admin')->event('settings_updated')->withProperties(['group' => $group, 'platform' => $platform, 'changes' => $changes])->log("Settings changed: {$group}");
        $this->securityLogger->log(SecurityEventType::SettingsChanged, null, ['group' => $group, 'keys' => array_keys($changes), 'platform' => $platform]);
    }

    private function assertGroup(string $group): void
    {
        if (! $this->registry->has($group)) {
            throw new BusinessRuleException('Settings group not found.', 'NOT_FOUND', 404);
        }
    }
}
