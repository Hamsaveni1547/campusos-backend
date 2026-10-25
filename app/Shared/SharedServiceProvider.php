<?php

namespace App\Shared;

use App\Models\User;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Files\FileAccessPolicy;
use App\Shared\Files\FileStorage;
use App\Shared\Http\RequestContext;
use App\Shared\Imports\ImportManager;
use App\Shared\Numbering\NumberGenerator;
use App\Shared\Settings\SettingsRegistry;
use App\Shared\Settings\SettingsRepository;
use App\Shared\Tenancy\CurrentInstitution;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Shared kernel: tenancy, request context, settings, files, numbering, imports, rate limits.
 */
class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons that are reset at the start of every request (AssignTraceId),
        // so cached controllers and long-running workers never see stale state.
        $this->app->singleton(CurrentInstitution::class);
        $this->app->singleton(RequestContext::class);
        $this->app->singleton(SettingsRegistry::class);
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(SettingsRepository::class);
        $this->app->singleton(FileStorage::class);
        $this->app->singleton(FileAccessPolicy::class);
        $this->app->singleton(ImportManager::class);
        $this->app->singleton(NumberGenerator::class);
    }

    public function boot(SettingsRegistry $settings): void
    {
        $settings->register('files', 'Files & uploads', [
            'max_upload_kb' => ['type' => 'int', 'default' => 10240, 'rules' => ['integer', 'min:100', 'max:51200'], 'label' => 'Maximum upload size (KB)'],
        ]);

        $settings->register('general', 'General', [
            'date_format' => ['type' => 'string', 'default' => 'd-m-Y', 'rules' => ['string', 'in:d-m-Y,d/m/Y,Y-m-d,m/d/Y,d M Y'], 'label' => 'Date format'],
            'time_format' => ['type' => 'string', 'default' => 'h:i A', 'rules' => ['string', 'in:h:i A,H:i'], 'label' => 'Time format'],
            'default_locale' => ['type' => 'string', 'default' => 'en', 'rules' => ['string', 'max:10'], 'label' => 'Default language'],
            'support_email' => ['type' => 'string', 'default' => '', 'rules' => ['nullable', 'email', 'max:255'], 'label' => 'Support email shown to users'],
        ]);

        $this->configureRateLimits();
    }

    private function configureRateLimits(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();

            return $user instanceof User
                ? Limit::perMinute(240)->by('user:'.$user->id)
                : Limit::perMinute(60)->by('ip:'.$request->ip());
        });

        RateLimiter::for('auth', fn (Request $request): array => [
            Limit::perMinute(30)->by('auth-ip:'.$request->ip()),
            Limit::perMinute(10)->by('auth-login:'.mb_strtolower((string) $request->input('login', $request->input('email', ''))).'|'.$request->ip()),
        ]);

        RateLimiter::for('mfa', fn (Request $request): array => [
            Limit::perMinute(20)->by('mfa-ip:'.$request->ip()),
            Limit::perMinute(10)->by('mfa-challenge:'.hash('sha256', (string) $request->input('challenge_token', ''))),
        ]);

        RateLimiter::for('otp', fn (Request $request): Limit => Limit::perMinutes(10, 6)->by('otp:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinute(30)->by('uploads:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Sanctum token model with device metadata.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
