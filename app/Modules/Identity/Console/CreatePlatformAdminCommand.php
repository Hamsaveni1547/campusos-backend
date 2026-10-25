<?php

namespace App\Modules\Identity\Console;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Services\PasswordPolicy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('campusos:create-platform-admin {--name= : Full name} {--email= : Sign-in email} {--password= : Password (prompted when omitted)}')]
#[Description('Create a platform administrator (operates all institutions)')]
class CreatePlatformAdminCommand extends Command
{
    public function handle(PasswordPolicy $policy): int
    {
        $name = $this->option('name') ?: text('Full name', required: true);
        $email = mb_strtolower((string) ($this->option('email') ?: text('Email', required: true)));
        $plainPassword = $this->option('password') ?: password('Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plainPassword],
            [
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', $policy->rule(null)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email]);
        $user->forceFill([
            'password' => $plainPassword,
            'status' => UserStatus::Active,
            'is_platform_admin' => true,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ])->save();

        $this->components->info("Platform administrator {$email} created. Two-step verification must be set up at first sign-in.");

        return self::SUCCESS;
    }
}
