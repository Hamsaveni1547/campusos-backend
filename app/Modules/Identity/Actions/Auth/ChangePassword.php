<?php

namespace App\Modules\Identity\Actions\Auth;

use App\Models\User;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Notifications\PasswordChangedNotification;
use App\Modules\Identity\Services\PasswordPolicy;
use App\Shared\Audit\SecurityEventType;
use App\Shared\Audit\SecurityLogger;
use App\Shared\Http\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Signed-in password change. Other sessions are signed out; the current one stays.
 */
class ChangePassword
{
    public function __construct(
        private PasswordPolicy $passwordPolicy,
        private SecurityLogger $securityLogger,
        private RequestContext $context,
    ) {}

    public function handle(User $user, string $newPassword, ?int $institutionId): void
    {
        $this->passwordPolicy->assertNotReused($user, $newPassword, $institutionId);

        /** @var PersonalAccessToken|null $current */
        $current = $user->currentAccessToken() instanceof PersonalAccessToken ? $user->currentAccessToken() : null;

        DB::transaction(function () use ($user, $newPassword, $current): void {
            $this->passwordPolicy->apply($user, $newPassword);

            $user->tokens()
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
                ->delete();
        });

        $this->securityLogger->log(SecurityEventType::PasswordChanged, $user, institutionId: $institutionId);
        $user->notify(new PasswordChangedNotification(['ip_address' => $this->context->ip()]));
    }
}
