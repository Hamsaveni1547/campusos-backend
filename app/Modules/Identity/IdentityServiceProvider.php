<?php

namespace App\Modules\Identity;

use App\Models\User;
use App\Modules\Communication\Support\NotificationTemplateRegistry;
use App\Modules\Identity\Console\CreatePlatformAdminCommand;
use App\Modules\Identity\Console\PruneAuthDataCommand;
use App\Modules\Identity\Console\SyncPermissionsCommand;
use App\Modules\Identity\Console\SyncRoleAssignmentsCommand;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Notifications\AccountSetupNotification;
use App\Modules\Identity\Notifications\OneTimeCodeNotification;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Identity\Notifications\SecurityAlerts;
use App\Modules\Identity\Support\SecuritySettings;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Http\RequestContext;
use App\Shared\Settings\SettingsRegistry;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Sanctum;

class IdentityServiceProvider extends ServiceProvider
{
    public function boot(SettingsRegistry $settings, NotificationTemplateRegistry $templates): void
    {
        SecuritySettings::register($settings);

        $templates->register(ResetPasswordNotification::class);
        $templates->register(AccountSetupNotification::class);
        $templates->register(OneTimeCodeNotification::class);

        foreach (SecurityAlerts::all() as $alert) {
            $templates->register($alert);
        }

        $this->configureTokens();
        $this->configureRouteBindings();

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreatePlatformAdminCommand::class,
                SyncPermissionsCommand::class,
                SyncRoleAssignmentsCommand::class,
                PruneAuthDataCommand::class,
            ]);
        }
    }

    /**
     * Tokens die when the account is not active or the device has been idle too long.
     */
    private function configureTokens(): void
    {
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid) {
                return false;
            }

            $user = $token->tokenable;

            if (! $user instanceof User || ! $user->status->canLogIn()) {
                return false;
            }

            $idleMinutes = app(SecuritySettings::class)->int('idle_timeout_minutes', $token->institution_id);
            $lastActivity = $token->last_used_at ?? $token->created_at;

            if ($idleMinutes > 0 && $lastActivity->lt(now()->subMinutes($idleMinutes))) {
                $token->delete();

                return false;
            }

            return true;
        });

        Event::listen(TokenAuthenticated::class, function (TokenAuthenticated $event): void {
            $event->token->forceFill(['last_used_ip' => app(RequestContext::class)->ip()]);
        });
    }

    private function configureRouteBindings(): void
    {
        // {user} resolves only to members of the current institution.
        Route::bind('user', function (string $value): User {
            $institutionId = app(CurrentInstitution::class)->id();

            $user = User::query()
                ->where('ulid', $value)
                ->when($institutionId !== null, fn ($query) => $query->whereHas('institutions', fn ($query) => $query->where('institutions.id', $institutionId)))
                ->first();

            if ($user === null || $institutionId === null) {
                throw new BusinessRuleException('The requested resource was not found.', 'NOT_FOUND', 404);
            }

            return $user;
        });

        // {role} resolves to built-in roles and the current institution's custom roles.
        Route::bind('role', function (string $value): Role {
            $institutionId = app(CurrentInstitution::class)->require()->id;

            return Role::query()->availableTo($institutionId)->where('ulid', $value)->firstOr(
                fn () => throw new BusinessRuleException('The requested resource was not found.', 'NOT_FOUND', 404)
            );
        });
    }
}
